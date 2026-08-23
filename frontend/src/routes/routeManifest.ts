import type { LucideIcon } from "lucide-react";
import {
  CalendarClock,
  CheckCircle2,
  ClipboardList,
  Home,
  UserRound,
} from "lucide-react";

/**
 * 一般ユーザー向けナビ(AppLayout)の5グループ。管理者向け(AdminLayout)は対象外
 * (adminNavGroups.tsに別管理のまま)。
 */
export type NavGroupKey =
  | "home"
  | "attendance"
  | "requests"
  | "approvals"
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
}

export interface RouteManifestEntry {
  /** ナビ表示名。 */
  label: string;
  /**
   * ガード・アクティブ判定に使う正規化されたパス(月次勤怠のように実際のリンク先が
   * 年月で変わる場合も、ここは常に固定のprefixにする)。
   */
  to: string;
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
    label: "承認待ち",
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
    group: "approvals",
    show: (ctx) => ctx.canSeeBackOfficeTasks,
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
