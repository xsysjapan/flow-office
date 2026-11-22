import { useCallback, useMemo, useRef } from "react";
import {
  createSearchParams,
  useLocation,
  useSearchParams,
  type SetURLSearchParams,
} from "react-router-dom";
import { getSafeRedirectTarget } from "../auth/redirectTarget";
import { resolvePage } from "../routes/pageHierarchy";

/**
 * 画面階層の「戻る」導線のための遷移元記録(`location.state`)。
 * spec: docs/changesets/20261121-navigation-hierarchy/spec.md 共通部品2
 */

export type BackTo = {
  pathname: string;
  search: string;
  hash: string;
  backTo?: BackTo;
};

export type BackState = { backTo: BackTo };

/** 遷移元の入れ子の上限段数。超えた古い段は捨て、その先は親画面へ戻る。 */
export const MAX_BACK_DEPTH = 5;

const FALLBACK_LABEL = "前の画面";

/** 任意の値を検証しつつ、{ pathname, search, hash, backTo }だけを残して`depth`段までに切り詰める。 */
function sanitize(value: unknown, depth: number): BackTo | undefined {
  if (depth <= 0 || typeof value !== "object" || value === null) {
    return undefined;
  }
  const v = value as Record<string, unknown>;
  if (
    typeof v.pathname !== "string" ||
    typeof v.search !== "string" ||
    typeof v.hash !== "string"
  ) {
    return undefined;
  }
  const next = sanitize(v.backTo, depth - 1);
  return {
    pathname: v.pathname,
    search: v.search,
    hash: v.hash,
    ...(next ? { backTo: next } : {}),
  };
}

function readBackTo(state: unknown, depth = MAX_BACK_DEPTH): BackTo | undefined {
  if (typeof state !== "object" || state === null) return undefined;
  return sanitize((state as { backTo?: unknown }).backTo, depth);
}

/** 画面内から別の画面へ進むリンク・navigateに渡す`state`(現在の画面を遷移元として記録する)。 */
export function useForwardState(): BackState {
  const location = useLocation();
  return useMemo(() => {
    const previous = readBackTo(location.state, MAX_BACK_DEPTH - 1);
    return {
      backTo: {
        pathname: location.pathname,
        search: location.search,
        hash: location.hash,
        ...(previous ? { backTo: previous } : {}),
      },
    };
  }, [location.pathname, location.search, location.hash, location.state]);
}

export interface BackTarget {
  to: string;
  state: BackState | undefined;
  label: string;
}

function labelFor(to: string): string {
  const pathname = to.split(/[?#]/)[0];
  return resolvePage(pathname)?.title ?? FALLBACK_LABEL;
}

/**
 * 戻り先を解決する。優先順: override.to → 遷移元(`location.state.backTo`) → 階層上の親。
 * ただし`menuEntry`の画面は遷移元が無ければ親へ戻さない。いずれも無ければnull。`state`は戻り先が持っていた遷移元を復元したもの(親へ戻るときはundefined)。
 */
export function useBackTarget(override?: {
  to?: string;
  label?: string;
}): BackTarget | null {
  const location = useLocation();
  const overrideTo = override?.to;
  const overrideLabel = override?.label;

  return useMemo(() => {
    if (overrideTo) {
      return {
        to: overrideTo,
        state: undefined,
        label: overrideLabel ?? labelFor(overrideTo),
      };
    }

    const recorded = readBackTo(location.state);
    if (recorded) {
      const safe = getSafeRedirectTarget(recorded.pathname + recorded.search);
      if (safe) {
        const to = safe + (recorded.hash.startsWith("#") ? recorded.hash : "");
        return {
          to,
          state: recorded.backTo ? { backTo: recorded.backTo } : undefined,
          label: overrideLabel ?? labelFor(to),
        };
      }
    }

    // サイドバーから開ける画面は、遷移元が無ければ掘り下げで来ていないので戻るリンクを出さない。
    const page = resolvePage(location.pathname);
    if (page?.menuEntry) return null;
    const parentPath = page?.parentPath;
    if (parentPath) {
      return {
        to: parentPath,
        state: undefined,
        label: overrideLabel ?? labelFor(parentPath),
      };
    }
    return null;
  }, [overrideTo, overrideLabel, location.pathname, location.state]);
}

/** 同じ階層内の移動・保存後の遷移に渡す、現在の`{ backTo }`(無ければundefined)。 */
export function useKeepBackState(): BackState | undefined {
  const location = useLocation();
  return useMemo(() => {
    const backTo = readBackTo(location.state);
    return backTo ? { backTo } : undefined;
  }, [location.state]);
}

/** `useSearchParams`と同じ形で、`setSearchParams`に現在の`state`を自動で足すラッパー。 */
export function useSearchParamsKeepingBackState(
  ...args: Parameters<typeof useSearchParams>
): [URLSearchParams, SetURLSearchParams] {
  const [searchParams, setSearchParams] = useSearchParams(...args);
  const keep = useKeepBackState();
  // 関数形式の`prev`は直前の描画時点の値になるため、同一イベント内の連続更新で古い値を元に上書きしてしまう。
  // 確定した検索条件をrefで保持し、関数形式の更新はそこから評価する(ルーターから変わった時だけ同期する)。
  const latestRef = useRef(searchParams);
  const seenRef = useRef(searchParams.toString());
  if (searchParams.toString() !== seenRef.current) {
    seenRef.current = searchParams.toString();
    latestRef.current = searchParams;
  }
  const setKeeping: SetURLSearchParams = useCallback(
    (nextInit, navigateOptions) => {
      const resolved =
        typeof nextInit === "function"
          ? nextInit(new URLSearchParams(latestRef.current))
          : nextInit;
      latestRef.current = createSearchParams(resolved);
      setSearchParams(resolved, {
        ...navigateOptions,
        state: navigateOptions?.state ?? keep,
      });
    },
    [setSearchParams, keep],
  );
  return [searchParams, setKeeping];
}
