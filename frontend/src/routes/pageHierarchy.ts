import { matchPath } from "react-router-dom";
import { adminNavGroups } from "../components/AdminLayout/adminNavGroups";

/**
 * 画面階層(画面名と親画面)を1か所で定義する。BackLink・サイドバーの点灯・document.titleが参照する。
 * ルートを追加したらここにも追加すること(App.tsxの全ルートが登録済みかはテストで検査する)。
 * spec: docs/changesets/20261121-navigation-hierarchy/spec.md
 */

type Params = Record<string, string | undefined>;

export interface PageNode {
  /** App.tsxのルートと同じ形式の絶対パス。 */
  pattern: string;
  title: string | ((params: Params) => string);
  /** 親の実パス。null は第1階層。パラメータを含む親は関数で組み立てる。 */
  parent: null | string | ((params: Params) => string);
  /** サイドバーから開ける画面。遷移元の記録が無いとき親へ戻るリンクを出さない */
  menuEntry?: true;
}

export interface ResolvedPage {
  title: string;
  parentPath: string | null;
  menuEntry: boolean;
}

const YEAR_MONTH = /^(\d{4})-(0[1-9]|1[0-2])$/;
const DATE = /^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/;
const MONTHS_PATH = "/attendance/months";

const adminListNodes: PageNode[] = adminNavGroups.flatMap((group) =>
  group.items.map((item) => ({
    pattern: item.to,
    title: item.label,
    parent: null,
  })),
);

export const pageNodes: PageNode[] = [
  { pattern: "/", title: "ホーム", parent: null },
  { pattern: "/attendance/week", title: "週次勤怠", parent: null },
  { pattern: MONTHS_PATH, title: "月次勤怠", parent: null },
  {
    pattern: "/attendance/months/:yearMonth",
    title: ({ yearMonth }) => {
      const m = YEAR_MONTH.exec(yearMonth ?? "");
      return m ? `${m[1]}年${Number(m[2])}月の勤怠` : "月次勤怠";
    },
    parent: MONTHS_PATH,
    menuEntry: true,
  },
  {
    pattern: "/attendance/days/:date",
    title: ({ date }) => {
      const m = DATE.exec(date ?? "");
      return m ? `${Number(m[2])}月${Number(m[3])}日の勤怠` : "日次勤怠";
    },
    parent: ({ date }) => {
      const m = DATE.exec(date ?? "");
      return m ? `${MONTHS_PATH}/${m[1]}-${m[2]}` : MONTHS_PATH;
    },
    menuEntry: true,
  },
  { pattern: "/requests", title: "申請一覧", parent: null },
  { pattern: "/requests/new", title: "新規申請", parent: "/requests" },
  { pattern: "/requests/:id", title: "申請詳細", parent: "/requests" },
  { pattern: "/approvals", title: "承認一覧", parent: null },
  { pattern: "/expenses", title: "経費精算", parent: null },
  { pattern: "/expenses/new", title: "経費精算を作成", parent: "/expenses" },
  { pattern: "/expenses/:id", title: "経費精算詳細", parent: "/expenses" },
  {
    pattern: "/expenses/:id/edit",
    title: "経費精算を編集",
    parent: ({ id }) => `/expenses/${id}`,
  },
  { pattern: "/expenses/presets", title: "入力プリセット", parent: "/expenses" },
  {
    pattern: "/expenses/presets/new",
    title: "プリセットの新規作成",
    parent: "/expenses/presets",
  },
  {
    pattern: "/expenses/presets/:id",
    title: "プリセットの編集",
    parent: "/expenses/presets",
  },
  { pattern: "/paid-leave", title: "有給", parent: null },
  { pattern: "/paid-leave/history", title: "有給履歴", parent: "/paid-leave" },
  { pattern: "/compensatory-leave", title: "代休", parent: null },
  { pattern: "/special-leave", title: "特別休暇", parent: null },
  {
    pattern: "/special-leave/history",
    title: "特別休暇履歴",
    parent: "/special-leave",
  },
  { pattern: "/backoffice-tasks", title: "タスク一覧", parent: null },
  {
    pattern: "/backoffice-tasks/:id",
    title: "タスク詳細",
    parent: "/backoffice-tasks",
  },
  { pattern: "/assets", title: "備品管理", parent: null },
  { pattern: "/assets/new", title: "備品を登録", parent: "/assets" },
  { pattern: "/assets/bulk/self-loan", title: "セルフ一括貸出", parent: "/assets" },
  { pattern: "/assets/bulk/self-return", title: "セルフ一括返却", parent: "/assets" },
  {
    pattern: "/assets/bulk/lend",
    title: "バックオフィス一括貸与",
    parent: "/assets",
  },
  {
    pattern: "/assets/bulk/return",
    title: "バックオフィス一括返却",
    parent: "/assets",
  },
  { pattern: "/assets/bulk/relocate", title: "備品の一括移設", parent: "/assets" },
  { pattern: "/assets/:id", title: "備品詳細", parent: "/assets" },
  {
    pattern: "/assets/:id/edit",
    title: "備品を編集",
    parent: ({ id }) => `/assets/${id}`,
  },
  { pattern: "/account", title: "アカウント設定", parent: null },
  { pattern: "/integrations", title: "API・MCP連携", parent: null },
  { pattern: "/notifications", title: "通知", parent: null },
  { pattern: "/admin", title: "管理メニュー", parent: null },
  ...adminListNodes,
  { pattern: "/admin/users/:id", title: "利用者詳細", parent: "/admin/users" },
  {
    pattern: "/admin/groups/:id",
    title: ({ id }) => (id === "new" ? "グループを新規作成" : "グループ詳細"),
    parent: "/admin/groups",
  },
  {
    pattern: "/admin/request-types/:id",
    title: ({ id }) => (id === "new" ? "申請種別の新規作成" : "申請種別の編集"),
    parent: "/admin/request-types",
  },
  {
    pattern: "/admin/expense-categories/:id",
    title: ({ id }) => (id === "new" ? "経費区分の新規作成" : "経費区分の編集"),
    parent: "/admin/expense-categories",
  },
  {
    pattern: "/admin/work-calendars/new",
    title: "会社カレンダーを作成",
    parent: "/admin/work-calendars",
  },
  {
    pattern: "/admin/work-calendars/:id",
    title: "会社カレンダー詳細",
    parent: "/admin/work-calendars",
  },
  {
    pattern: "/admin/work-calendars/:calendarId/years/:fiscalYear/days",
    title: ({ fiscalYear }) =>
      /^\d{4}$/.test(fiscalYear ?? "") ? `${fiscalYear}年度の日別設定` : "日別設定",
    parent: ({ calendarId }) => `/admin/work-calendars/${calendarId}`,
  },
  {
    pattern: "/admin/paid-leave/schedule",
    title: "付与予定",
    parent: "/admin/paid-leave",
  },
  {
    pattern: "/admin/paid-leave/rules/:ruleId/edit",
    title: "付与ルールを編集",
    parent: "/admin/paid-leave",
  },
];

/** パターンの具体さ(リテラルセグメント2点、パラメータ1点)。同じパスに複数一致したとき高い方を採る。 */
function specificity(pattern: string): number {
  return pattern
    .split("/")
    .filter(Boolean)
    .reduce((sum, seg) => sum + (seg.startsWith(":") ? 1 : 2), 0);
}

function normalize(pathname: string): string {
  return pathname.length > 1 ? pathname.replace(/\/+$/, "") : pathname;
}

function findNode(
  pathname: string,
): { node: PageNode; params: Params } | null {
  const path = normalize(pathname);
  let best: { node: PageNode; params: Params; score: number } | null = null;
  for (const node of pageNodes) {
    const m = matchPath({ path: node.pattern, end: true }, path);
    if (!m) continue;
    const score = specificity(node.pattern);
    if (!best || score > best.score) {
      best = { node, params: m.params, score };
    }
  }
  return best;
}

/** 最も具体的に一致するノードから画面名と親パスを返す。一致なしはnull。 */
export function resolvePage(pathname: string): ResolvedPage | null {
  const found = findNode(pathname);
  if (!found) return null;
  const { node, params } = found;
  return {
    title: typeof node.title === "function" ? node.title(params) : node.title,
    parentPath:
      typeof node.parent === "function" ? node.parent(params) : node.parent,
    menuEntry: node.menuEntry === true,
  };
}

const MAX_ANCESTORS = 10;

/** 自身から親をたどったパスの配列(自身を含む。循環防止で最大10)。 */
export function ancestorsOf(pathname: string): string[] {
  const result: string[] = [];
  let current: string | null = normalize(pathname);
  while (current !== null && result.length < MAX_ANCESTORS) {
    if (result.includes(current)) break;
    result.push(current);
    current = resolvePage(current)?.parentPath ?? null;
  }
  return result;
}

/**
 * サイドバーで点灯させるnavPathsの要素を返す。祖先チェーンを自身から近い順にたどり、
 * 最初にナビ項目と一致した段で、一致した項目のうちtoが最長のもの(同じtoの重複は全て)を返す。
 * `exactOnly`に含まれるnavPathは完全一致のみ(配下のパスでは点灯させない)。
 */
export function findActiveNavPath(
  pathname: string,
  navPaths: string[],
  exactOnly: string[] = [],
): string[] {
  for (const p of ancestorsOf(pathname)) {
    const matched = navPaths.filter((to) =>
      exactOnly.includes(to)
        ? p === to
        : p === to || p.startsWith(`${to}/`),
    );
    if (matched.length > 0) {
      const longest = Math.max(...matched.map((to) => to.length));
      return matched.filter((to) => to.length === longest);
    }
  }
  return [];
}
