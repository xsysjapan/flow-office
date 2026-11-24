import type { LucideIcon } from "lucide-react";
import { findActiveNavPath } from "./pageHierarchy";
import {
  Boxes,
  CalendarClock,
  CheckCircle2,
  ClipboardList,
  Home,
  UserRound,
} from "lucide-react";

/**
 * 一般ユーザー向けナビ(AppLayout)の6グループ。管理者向け(AdminLayout)は対象外
 * (adminNavGroups.tsに別管理のまま)。
 */
export type NavGroupKey =
  | "home"
  | "attendance"
  | "requests"
  | "approvals"
  | "backoffice"
  | "mypage";

export interface NavContext {
  /** 月次勤怠リンクの遷移先に使う「今月」(YYYY-MM)。 */
  currentYearMonth: string;
  /** 日次勤怠リンクの遷移先に使う「今日」(YYYY-MM-DD)。 */
  todayDate: string;
  /** 有効な特別休暇種別が1件以上あるか(無ければ「特別休暇」リンク自体を出さない)。 */
  hasSpecialLeaveTypes: boolean;
  /** バックオフィスタスク一覧を見せてよいか(feature判定込み)。 */
  canSeeBackOfficeTasks: boolean;
  /** 管理メニュー配下に見えるページが1つでもあるか。 */
  canAccessAdmin: boolean;
  /** 備品管理Permission(`asset.manage`)を持つか。 */
  canManageAssets: boolean;
}

export interface RouteManifestEntry {
  /** ナビ表示名。 */
  label: string;
  /**
   * ガード・アクティブ判定に使う正規化されたパス(月次勤怠のように実際のリンク先が
   * 年月で変わる場合も、ここは常に固定のprefixにする)。
   */
  to: string;
  /**
   * 同じパスの項目を入口ごとに区別するための、一致させるクエリ(キー→値)。
   * パスが一致し、全キーが一致する項目のうち、一致したキーが最も多い項目だけが点灯する。
   * spec: docs/changesets/20261124-list-display-fixes/spec.md 論点2
   */
  matchQuery?: Record<string, string>;
  /** 実際のリンク先。省略時は`to`をそのまま使う(年月など動的な行き先だけ指定する)。 */
  buildTo?: (ctx: NavContext) => string;
  /** この画面の閲覧に必要なfeature。複数指定時はいずれか1つを満たせばよい。未指定は誰でも見られる。 */
  feature?: string | string[];
  group: NavGroupKey;
  /** feature判定に加えて、この関数がfalseを返す間はナビに出さない(特別休暇種別の有無等)。 */
  show?: (ctx: NavContext) => boolean;
}

/**
 * ナビゲーション項目と、画面ごとに必要なfeatureの定義を1箇所に集約したマニフェスト。
 * AppLayoutのナビ生成と、featureを満たさないURLへ直接アクセスした際のガード
 * (旧: AppLayout内にハードコードされていた`required`switch)の両方がこの配列だけを見る。
 * 個別ページのURLパス・コンポーネント自体は変更しない(ここは参照のみ追加する)。
 */
export const routeManifest: RouteManifestEntry[] = [
  {
    label: "日次勤怠",
    to: "/attendance/days",
    buildTo: (ctx) => `/attendance/days/${ctx.todayDate}`,
    feature: "attendance.entry",
    group: "attendance",
  },
  {
    label: "週次勤怠",
    to: "/attendance/week",
    feature: "attendance.entry",
    group: "attendance",
  },
  {
    label: "月次勤怠",
    to: "/attendance/months",
    buildTo: (ctx) => `/attendance/months/${ctx.currentYearMonth}`,
    feature: "attendance.timesheet",
    group: "attendance",
  },
  {
    label: "申請一覧",
    to: "/requests",
    feature: "workflow.requests",
    group: "requests",
  },
  {
    label: "有給",
    to: "/paid-leave",
    feature: "paid_leave.requests",
    group: "requests",
  },
  {
    label: "代休",
    to: "/compensatory-leave",
    feature: "paid_leave.requests",
    group: "requests",
  },
  {
    label: "特別休暇",
    to: "/special-leave",
    feature: "paid_leave.requests",
    group: "requests",
    show: (ctx) => ctx.hasSpecialLeaveTypes,
  },
  {
    label: "経費精算",
    to: "/expenses",
    feature: "backoffice.expenses",
    group: "requests",
  },
  {
    // 検索・詳細・セルフ貸出/返却はPermission不要(認証済みなら誰でも)。
    // spec: docs/changesets/20260831-asset-management-refinement/spec.md 論点8
    // 20261124-list-display-fixes 論点2で、利用可能な貸出品の絞り込み付きに改めた。
    label: "備品貸出",
    to: "/assets",
    buildTo: () => "/assets?management_type=lending&lending_status=available",
    matchQuery: { management_type: "lending", lending_status: "available" },
    group: "requests",
  },
  {
    label: "承認一覧",
    to: "/approvals",
    feature: [
      "attendance.timesheet",
      "paid_leave.requests",
      "workflow.requests",
      "backoffice.expenses",
    ],
    group: "approvals",
  },
  {
    label: "タスク一覧",
    to: "/backoffice-tasks",
    feature: "backoffice.tasks",
    group: "backoffice",
    show: (ctx) => ctx.canSeeBackOfficeTasks,
  },
  {
    // バックオフィス担当者(備品管理権限保持者)向けの管理導線。遷移先は「備品貸出」
    // (requestsグループ)と同じ一覧ページで、権限に応じた操作が出し分けられる。
    // spec: docs/changesets/20260831-asset-management-refinement/spec.md 論点8
    label: "備品管理",
    to: "/assets",
    // `asset.manage`はPermission(Featureではない)。メニューはPermissionで出し分ける。
    group: "backoffice",
    show: (ctx) => ctx.canManageAssets,
  },
  {
    label: "アカウント設定",
    to: "/account",
    group: "mypage",
  },
  {
    label: "API・MCP連携",
    to: "/integrations",
    group: "mypage",
  },
  {
    // 管理メニューへのアクセス可否は`effective_features`の単純な文字列一致ではなく、
    // adminNavGroups側の各サブメニューがpermission込みで判定するcanAccessAdminItemに
    // 委ねる(AdminLayoutと同じ判定)。ここではその結果(ctx.canAccessAdmin)だけを見る。
    label: "管理メニュー",
    to: "/admin",
    group: "mypage",
    show: (ctx) => ctx.canAccessAdmin,
  },
];

export const navGroupMeta: Record<
  NavGroupKey,
  { label: string; icon: LucideIcon }
> = {
  home: { label: "ホーム", icon: Home },
  attendance: { label: "勤怠", icon: CalendarClock },
  requests: { label: "申請", icon: ClipboardList },
  approvals: { label: "承認", icon: CheckCircle2 },
  backoffice: { label: "バックオフィス", icon: Boxes },
  mypage: { label: "マイページ", icon: UserRound },
};

/** ホーム(ダッシュボード)への固定リンク。他グループと違い項目1つの固定リンクなので
 *  routeManifestには含めず、レイアウト側で先頭に足す。 */
export const HOME_PATH = "/";

/** そのナビ項目(またはその配下のページ)を今表示しているか。前方一致するパス同士
 *  (有給/有給履歴等)が同時にアクティブにならないよう、"/"は完全一致、それ以外は
 *  自身か"to/"始まりのパスのみ一致させる。 */
export function isPathActive(pathname: string, to: string): boolean {
  if (to === "/") return pathname === "/";
  return pathname === to || pathname.startsWith(`${to}/`);
}

function hasFeature(
  effectiveFeatures: string[] | undefined,
  feature: string | string[],
): boolean {
  if (Array.isArray(feature)) {
    return feature.some((f) => effectiveFeatures?.includes(f));
  }
  return Boolean(effectiveFeatures?.includes(feature));
}

/**
 * `effective_features`に基づき、そのマニフェスト項目を表示してよいか。
 * `effective_features`が未定義(取得前)の間は暫定的に全件表示する(既存挙動を踏襲)。
 */
export function isEntryVisible(
  entry: RouteManifestEntry,
  effectiveFeatures: string[] | undefined,
  ctx: NavContext,
): boolean {
  if (entry.show && !entry.show(ctx)) return false;
  if (!entry.feature) return true;
  if (effectiveFeatures === undefined) return true;
  return hasFeature(effectiveFeatures, entry.feature);
}

export interface ResolvedNavItem {
  to: string;
  label: string;
  /** サイドバー点灯判定用の正規化パス(= entry.to)。リンク先`to`は今日・今月などに変わり得る。 */
  matchPath: string;
  /** 同じパスの項目を区別するクエリ(entry.matchQuery)。 */
  matchQuery?: Record<string, string>;
}

export interface ResolvedNavGroup {
  key: NavGroupKey;
  label: string;
  icon: LucideIcon;
  items: ResolvedNavItem[];
}

/** routeManifestを実際にナビへ描画できる形(グループごとのリンク一覧)に組み立てる。
 *  feature判定・特別休暇/バックオフィスタスクの表示条件をすべてここに集約し、
 *  AppLayout側では組み立て済みの結果を描画するだけにする。 */
export function buildNavGroups(
  effectiveFeatures: string[] | undefined,
  ctx: NavContext,
): ResolvedNavGroup[] {
  const groups: NavGroupKey[] = [
    "attendance",
    "requests",
    "approvals",
    "backoffice",
    "mypage",
  ];
  return groups
    .map((key) => ({
      key,
      label: navGroupMeta[key].label,
      icon: navGroupMeta[key].icon,
      items: routeManifest
        .filter((entry) => entry.group === key)
        .filter((entry) => isEntryVisible(entry, effectiveFeatures, ctx))
        .map((entry) => ({
          to: entry.buildTo ? entry.buildTo(ctx) : entry.to,
          label: entry.label,
          matchPath: entry.to,
          matchQuery: entry.matchQuery,
        })),
    }))
    .filter((group) => group.items.length > 0);
}

/**
 * 現在のパスの閲覧に必要なfeatureを、routeManifestの最長prefix一致から求める。
 * 一致する項目が無い、またはfeature未指定なら`[]`(誰でも見られる)を返す。
 * ホーム(ダッシュボード)自体は特定のfeatureを要求しない。
 */
export function requiredFeaturesForPath(pathname: string): string[] {
  if (pathname === "/") return [];

  let matched: RouteManifestEntry | undefined;
  for (const entry of routeManifest) {
    if (isPathActive(pathname, entry.to) && entry.to.length > 1) {
      if (!matched || entry.to.length > matched.to.length) {
        matched = entry;
      }
    }
  }
  if (!matched?.feature) return [];
  return Array.isArray(matched.feature) ? matched.feature : [matched.feature];
}

export interface ActiveNavCandidate {
  matchPath: string;
  matchQuery?: Record<string, string>;
}

/**
 * 現在のURL(パス+クエリ)で点灯するナビ項目を選ぶ純関数。
 * 1. `matchQuery`のキーがすべて一致する項目だけを候補にする(`matchQuery`無しは常に候補)。
 * 2. 候補のパス一致は`findActiveNavPath`と同じ(祖先まで遡り、最長一致)。
 * 3. 同じパスの候補が複数あるときは、一致したキーの数が最も多い項目だけを返す。
 */
export function selectActiveNavItems<T extends ActiveNavCandidate>(
  pathname: string,
  search: string,
  items: T[],
  exactOnly: string[] = [],
): T[] {
  const params = new URLSearchParams(search);
  const candidates = items.filter((item) =>
    Object.entries(item.matchQuery ?? {}).every(
      ([key, value]) => params.get(key) === value,
    ),
  );
  const activePaths = findActiveNavPath(
    pathname,
    candidates.map((item) => item.matchPath),
    exactOnly,
  );
  const onPath = candidates.filter((item) =>
    activePaths.includes(item.matchPath),
  );
  const keyCount = (item: T) => Object.keys(item.matchQuery ?? {}).length;
  const most = Math.max(0, ...onPath.map(keyCount));
  return onPath.filter((item) => keyCount(item) === most);
}
