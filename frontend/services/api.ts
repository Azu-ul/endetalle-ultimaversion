import {
  Author,
  ClientUser,
  Comment,
  ErrorReport,
  ConstructionDetail,
  SiteConfig,
  CategoryDef,
  PricingPlan,
} from "../types";
import { DEFAULT_AVATAR } from "../constants";

const API_URL = import.meta.env.VITE_API_URL ?? "";

export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;
  constructor(
    message: string,
    status: number,
    errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

// Texto para mostrarle al usuario. En los 422 se toma el primer error del campo,
// porque el "message" de Laravel agrega "(and N more errors)" en inglés.
export function errorText(err: unknown, fallback: string): string {
  if (!(err instanceof ApiError)) return fallback;
  if (err.status === 422)
    return Object.values(err.errors)[0]?.[0] ?? err.message;
  if (err.status === 429) return "Demasiados intentos. Esperá un minuto.";
  if (err.status === 403) return err.message;
  return fallback;
}

function getCookie(name: string): string | null {
  const match = document.cookie.match(
    new RegExp("(?:^|; )" + name + "=([^;]*)"),
  );
  return match ? decodeURIComponent(match[1]) : null;
}

async function ensureCsrf() {
  if (getCookie("XSRF-TOKEN")) return;
  await fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: "include" });
}

export async function api<T = any>(
  path: string,
  options: { method?: string; body?: unknown } = {},
): Promise<T> {
  const method = options.method ?? "GET";
  if (method !== "GET") await ensureCsrf();

  // Los archivos van como FormData (el navegador arma el Content-Type)
  const isForm = options.body instanceof FormData;
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  if (options.body !== undefined && !isForm)
    headers["Content-Type"] = "application/json";
  const xsrf = getCookie("XSRF-TOKEN");
  if (xsrf) headers["X-XSRF-TOKEN"] = xsrf;

  const res = await fetch(`${API_URL}/api${path}`, {
    method,
    headers,
    credentials: "include",
    body:
      options.body === undefined
        ? undefined
        : isForm
          ? (options.body as FormData)
          : JSON.stringify(options.body),
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok)
    throw new ApiError(
      data.message ?? "Error de conexión",
      res.status,
      data.errors ?? {},
    );
  return data as T;
}

export interface ApiUser {
  id: number;
  name: string;
  email: string;
  email_verified: boolean;
  refund_eligible: boolean;
  avatar_url: string | null;
  cover_url: string | null;
  profession: string | null;
  company: string | null;
  bio: string | null;
  birth_date: string | null;
  interests: string[];
  portfolio_url: string | null;
  roles: string[];
  quota: { used: number; limit: number | null };
  plan: {
    slug: string;
    name: string;
    level: number;
    detail_limit: number | null;
    allows_dwg: boolean;
    allows_skp: boolean;
    allows_ebooks: boolean;
  };
  subscription: {
    status: string;
    provider: string | null;
    cancelled: boolean;
    starts_at: string | null;
    ends_at: string | null;
    days_left: number | null;
  } | null;
  scheduled: {
    plan: { slug: string; name: string };
    starts_at: string;
    ends_at: string;
  } | null;
}

export const AuthApi = {
  me: () => api<ApiUser>("/user"),
  login: (email: string, password: string) =>
    api<ApiUser>("/login", { method: "POST", body: { email, password } }),
  register: (data: {
    name: string;
    email: string;
    password: string;
    profession?: string;
    birth_date?: string | null;
    interests?: string[];
  }) => api<ApiUser>("/register", { method: "POST", body: data }),
  logout: () => api("/logout", { method: "POST" }),
  forgotPassword: (email: string) =>
    api<{ message: string }>("/forgot-password", { method: "POST", body: { email } }),
  resetPassword: (data: { token: string; email: string; password: string }) =>
    api<{ message: string }>("/reset-password", { method: "POST", body: data }),
  checkResetLink: (token: string, email: string) =>
    api<{ valid: boolean }>(
      `/reset-password/check?token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}`,
    ),
  resendVerification: () =>
    api<{ message: string }>("/email/resend", { method: "POST" }),
};

export interface CheckoutOrder {
  status: "pending" | "paid" | "refunded" | "expired" | "failed";
  provider: "mercadopago" | "paypal";
  plan: { slug: string; name: string };
  months: number;
  ends_at: string | null;
  scheduled: boolean; // plan menor pago que todavía no empezó
  starts_at: string | null;
}

export type PurchaseState =
  | "available"
  | "renew"
  | "upgrade"
  | "downgrade" // plan menor: se paga completo y empieza al vencer el actual
  | "scheduled" // ese plan ya quedó encolado
  | "blocked_same"
  | "blocked_lower"
  | "blocked_scheduled";

// Qué puede comprar el usuario con cada plan y cuánto paga (todo calculado en el servidor)
export interface CheckoutStatus {
  current: { plan: string; ends_at: string; cancelled: boolean } | null;
  plans: {
    slug: string;
    state: PurchaseState;
    message: string | null;
    ends_at: string | null;
    renew_from: string | null;
    starts_at: string | null;
    periods: {
      id: string;
      amount_ars: number;
      amount_usd: number;
    }[];
  }[];
}

export const SubscriptionApi = {
  cancel: () => api<ApiUser>("/subscription/cancel", { method: "POST" }),
};

export const CheckoutApi = {
  status: () => api<CheckoutStatus>("/checkout/status"),
  start: (data: {
    plan: string;
    period: string;
    provider: "mercadopago" | "paypal";
  }) => api<{ url: string; order: string }>("/checkout", { method: "POST", body: data }),
  order: (uuid: string) => api<CheckoutOrder>(`/orders/${uuid}`),
  capture: (uuid: string) =>
    api<CheckoutOrder>(`/orders/${uuid}/capture`, { method: "POST" }),
  // Revisa en Mercado Pago las compras sin acreditar (por si no se volvió al sitio después de pagar)
  // credited: órdenes acreditadas ahora (y entonces viene el usuario ya actualizado);
  // pending: compras recientes que Mercado Pago todavía no confirmó
  sync: () =>
    api<{ credited: number; pending: number; user?: ApiUser }>("/orders/sync", { method: "POST" }),
};

// ---------- Reembolsos (botón de arrepentimiento) ----------

export interface RefundOrder {
  id: number;
  plan: string;
  period: string;
  months: number;
  amount: number;
  currency: "ARS" | "USD";
  provider: "mercadopago" | "paypal";
  paid_at: string;
  deadline: string;
  within_window: boolean;
  order_status: string;
  refund: { code: string; status: "pending" | "approved" | "rejected"; rejection_reason: string | null } | null;
  can_request: boolean;
  message: string | null;
}

export const RefundApi = {
  list: () => api<{ window_days: number; orders: RefundOrder[] }>("/refunds"),
  request: (data: { order_id: number; reason?: string; phone?: string }) =>
    api<{ code: string; status: string; amount: number; currency: string }>("/refunds", { method: "POST", body: data }),
};

export type RefundStatus = "pending" | "approved" | "rejected";

export interface AdminRefund {
  id: number;
  code: string;
  status: RefundStatus;
  requested_at: string;
  name: string;
  email: string;
  phone: string | null;
  reason: string | null;
  plan: string;
  period: string;
  months: number;
  amount: number;
  refund_percent: number;
  order_amount: number;
  currency: "ARS" | "USD";
  provider: "mercadopago" | "paypal";
  provider_payment_id: string | null;
  paid_at: string | null;
  days_since_payment: number;
  within_window: boolean;
  order_status: string;
  reviewed_by: string | null;
  reviewed_at: string | null;
  admin_note: string | null;
  rejection_reason: string | null;
  has_receipt: boolean;
}

export const AdminRefundApi = {
  list: (status: RefundStatus | "all") =>
    api<{ pending_count: number; requests: AdminRefund[] }>(`/admin/refunds?status=${status}`),
  approve: (id: number, receipt: File, note: string) => {
    const form = new FormData();
    form.append("receipt", receipt);
    if (note.trim()) form.append("note", note.trim());
    return api<AdminRefund>(`/admin/refunds/${id}/approve`, { method: "POST", body: form });
  },
  reject: (id: number, reason: string) =>
    api<AdminRefund>(`/admin/refunds/${id}/reject`, { method: "POST", body: { reason } }),
};

export interface AdminOrder {
  id: number;
  user: { id: number; name: string; email: string };
  plan: string;
  period: string;
  months: number;
  amount: number;
  currency: "ARS" | "USD";
  status: string;
  provider: "mercadopago" | "paypal";
  provider_payment_id: string | null;
  kind: "upgrade" | "queued" | null;
  paid_at: string | null;
  created_at: string;
}

export const AdminOrdersApi = {
  list: (params: { status?: string; provider?: string; q?: string }) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => v && v !== "all" && query.set(k, v));
    return api<AdminOrder[]>(`/admin/orders?${query.toString()}`);
  },
};

// El comprobante es privado: se pide con la sesión del admin y se guarda con su nombre
export async function downloadRefundReceipt(id: number): Promise<void> {
  const res = await fetch(`${API_URL}/api/admin/refunds/${id}/receipt`, {
    headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    credentials: "include",
  });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new ApiError(data.message ?? "Error de conexión", res.status);
  }
  const disposition = res.headers.get("Content-Disposition") ?? "";
  const name =
    /filename\*=UTF-8''([^;]+)/i.exec(disposition)?.[1] ??
    /filename="?([^";]+)"?/i.exec(disposition)?.[1];
  const url = URL.createObjectURL(await res.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = name ? decodeURIComponent(name) : `comprobante-${id}`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

export const ContactApi = {
  send: (data: { name: string; email: string; subject: string; message: string }) =>
    api<{ message: string }>("/contact", { method: "POST", body: data }),
};

export interface ApiSession {
  device: string;
  ip_address: string | null;
  last_activity: string;
  current: boolean;
}

export type ProfileImageKind = "avatar" | "cover";

export const AccountApi = {
  updateProfile: (data: {
    name: string;
    profession?: string | null;
    bio?: string | null;
    birth_date?: string | null;
  }) => api<ApiUser>("/user/profile", { method: "PATCH", body: data }),
  uploadImage: (kind: ProfileImageKind, file: File) => {
    const form = new FormData();
    form.append("image", file);
    return api<ApiUser>(`/user/${kind}`, { method: "POST", body: form });
  },
  deleteImage: (kind: ProfileImageKind) =>
    api<ApiUser>(`/user/${kind}`, { method: "DELETE" }),
  changePassword: (data: {
    current_password: string;
    password: string;
    password_confirmation: string;
  }) =>
    api<{ message: string; closed_sessions: number }>("/user/password", {
      method: "PUT",
      body: data,
    }),
  sessions: () => api<ApiSession[]>("/user/sessions"),
  logoutOtherSessions: (password: string) =>
    api<{ closed_sessions: number }>("/user/sessions/logout-others", {
      method: "POST",
      body: { password },
    }),
};

// Traduce la respuesta de la API al formato que hoy usa la interfaz.
// El plan es provisorio: free = 'Basic', cualquier plan pago = 'Pro'.
export function toClientUser(u: ApiUser): ClientUser {
  return {
    id: String(u.id),
    name: u.name,
    email: u.email,
    avatarUrl: u.avatar_url ?? DEFAULT_AVATAR,
    plan: u.plan.name,
    status: "Active",
    subscriptionStart: u.subscription?.starts_at?.slice(0, 10) ?? "",
    subscriptionEnd: u.subscription?.ends_at?.slice(0, 10) ?? "",
    monthlyFee: 0,
    profession: u.profession ?? undefined,
    bio: u.bio ?? undefined,
    birthDate: u.birth_date ?? undefined,
    interests: u.interests,
    coverUrl: u.cover_url ?? undefined,
    quotaUsed: u.quota.used,
    quotaLimit: u.quota.limit,
    daysLeft: u.subscription?.days_left ?? null,
    roles: u.roles,
    emailVerified: u.email_verified,
    planSlug: u.plan.slug,
    subscriptionEndsAt: u.subscription?.ends_at ?? null,
    subscriptionPaid: !!u.subscription && u.subscription.provider !== "manual",
    subscriptionCancelled: u.subscription?.cancelled ?? false,
    refundEligible: u.refund_eligible,
    scheduledPlan: u.scheduled
      ? { name: u.scheduled.plan.name, startsAt: u.scheduled.starts_at }
      : undefined,
  };
}

// ---------- Catálogo, categorías, planes y ajustes ----------

export interface ApiDetailCard {
  id: number;
  slug: string;
  title: string;
  description: string;
  cover_url: string | null;
  category: { id: number; name: string; slug: string } | null;
  subcategory: { id: number; name: string; slug: string } | null;
  author: {
    id: number;
    name: string;
    avatar_url: string | null;
    profession: string | null;
    company: string | null;
  } | null;
  tags: string[];
  is_verified: boolean;
  votes_count: number;
  published_at: string | null;
  required_plan: { slug: string; name: string; level: number } | null;
  locked: boolean;
}

export interface ApiDetailFull extends ApiDetailCard {
  access: {
    allowed: boolean;
    reason: "login" | "plan" | "quota" | "verify" | null;
  };
  specs?: string[];
  materials?: string[];
  images?: { type: string; url: string; caption: string | null }[];
  seo_title?: string | null;
  meta_description?: string | null;
  has_voted?: boolean;
  files?: { type: "dwg" | "skp"; can_download: boolean }[];
}

export interface ApiCategory {
  id: number;
  name: string;
  slug: string;
  image_url: string | null;
  subcategories: { id: number; name: string; slug: string }[];
}

export interface ApiPlan {
  id: number;
  slug: string;
  name: string;
  price: number;
  price_ars: number;
  price_usd: number;
  periods: {
    id: "monthly" | "quarterly" | "yearly";
    months: number;
    discount_percent: number;
    total_ars: number;
    total_usd: number;
    per_month_ars: number;
    per_month_usd: number;
  }[];
  interval: string;
  description: string | null;
  features: string[];
  is_recommended: boolean;
  highlight_color: string | null;
}

export const SiteApi = {
  settings: () => api<Record<string, any>>("/settings"),
  categories: () => api<ApiCategory[]>("/categories"),
  plans: () => api<ApiPlan[]>("/plans"),
};

export const DetailsApi = {
  list: () => api<{ data: ApiDetailCard[] }>("/details?per_page=100"),
  show: (slug: string) =>
    api<ApiDetailFull>(`/details/${encodeURIComponent(slug)}`),
};

// ---------- Comunidad: directorio, perfiles públicos y "me gusta" ----------

export interface ApiPublicUser {
  id: number;
  name: string;
  avatar_url: string | null;
  profession: string | null;
  company: string | null;
  is_author: boolean;
  details_count: number;
  likes_count: number;
  liked_by_me: boolean;
}

export interface ApiPublicProfile extends ApiPublicUser {
  cover_url: string | null;
  bio: string | null;
  portfolio_url: string | null;
  member_since: string | null;
  votes_received: number;
  details: ApiDetailCard[];
}

export const UsersApi = {
  directory: (q: string, page: number) =>
    api<{ data: ApiPublicUser[]; current_page: number; last_page: number }>(
      `/users?q=${encodeURIComponent(q)}&page=${page}`,
    ),
  show: (id: string) =>
    api<ApiPublicProfile>(`/users/${encodeURIComponent(id)}`),
  like: (id: string, liked: boolean) =>
    api<{ liked: boolean; likes_count: number }>(`/users/${id}/like`, {
      method: liked ? "POST" : "DELETE",
    }),
};

export function toPublicUser(u: ApiPublicUser | ApiPublicProfile): Author {
  const p = u as Partial<ApiPublicProfile>;
  return {
    id: String(u.id),
    name: u.name,
    avatarUrl: u.avatar_url ?? DEFAULT_AVATAR,
    role: u.profession ?? "",
    team: u.company ?? undefined,
    isAuthor: u.is_author,
    detailsCount: u.details_count,
    likesCount: u.likes_count,
    likedByMe: u.liked_by_me,
    coverUrl: p.cover_url ?? undefined,
    bio: p.bio ?? undefined,
    portfolioUrl: p.portfolio_url ?? undefined,
    votesReceived: p.votes_received,
    memberSince: p.member_since ?? undefined,
  };
}

export function toDetail(d: ApiDetailCard | ApiDetailFull): ConstructionDetail {
  const full = d as Partial<ApiDetailFull>;
  return {
    id: String(d.id),
    slug: d.slug,
    title: d.title,
    category: d.category?.name ?? "",
    subcategory: d.subcategory?.name,
    tags: d.tags,
    description: d.description,
    imageUrl: d.cover_url ?? "",
    specs: full.specs ?? [],
    materials: full.materials ?? [],
    drawings: (full.images ?? []).map((i) => ({
      type: (i.type.charAt(0).toUpperCase() + i.type.slice(1)) as any,
      url: i.url,
      caption: i.caption ?? "",
    })),
    author: d.author
      ? toAuthor(d.author)
      : { name: "enDetalle", avatarUrl: DEFAULT_AVATAR, role: "" },
    isVerified: d.is_verified,
    likes: d.votes_count,
    status: "Published",
    accessLevel: !d.required_plan
      ? "Public"
      : d.required_plan.slug === "estudiante"
        ? "Estudiante"
        : "Pro",
    publishedDate: d.published_at ?? "",
    seoTitle: full.seo_title ?? undefined,
    metaDescription: full.meta_description ?? undefined,
    locked: d.locked,
    requiredPlanName: d.required_plan?.name,
    hasVoted: full.has_voted ?? false,
    files: (full.files ?? []).map((f) => ({
      type: f.type,
      canDownload: f.can_download,
    })),
  };
}

// ---------- Favoritos, voto técnico, comentarios y reportes ----------

export interface ApiComment {
  id: number;
  parent_id: number | null;
  body: string;
  created_at: string;
  user: { id: number; name: string; avatar_url: string | null };
  badge: string | null;
  can_delete: boolean;
  can_edit: boolean;
  edited: boolean;
  replies: ApiComment[];
}

// Descarga de DWG / SKP: se pide con la sesión y se guarda con el nombre original
export async function downloadDetailFile(
  detailId: string,
  type: "dwg" | "skp",
): Promise<void> {
  const res = await fetch(`${API_URL}/api/details/${detailId}/files/${type}`, {
    headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
    credentials: "include",
  });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new ApiError(data.message ?? "Error de conexión", res.status);
  }
  const disposition = res.headers.get("Content-Disposition") ?? "";
  const name =
    /filename\*=UTF-8''([^;]+)/i.exec(disposition)?.[1] ??
    /filename="?([^";]+)"?/i.exec(disposition)?.[1];
  const url = URL.createObjectURL(await res.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = name ? decodeURIComponent(name) : `detalle.${type}`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

export const SocialApi = {
  favorites: () => api<number[]>("/favorites"),
  favoriteDetails: () => api<ApiDetailCard[]>("/favorites/details"),
  addFavorite: (detailId: string) =>
    api(`/details/${detailId}/favorite`, { method: "POST" }),
  removeFavorite: (detailId: string) =>
    api(`/details/${detailId}/favorite`, { method: "DELETE" }),
  vote: (detailId: string, voted: boolean) =>
    api<{ voted: boolean; votes_count: number }>(`/details/${detailId}/vote`, {
      method: voted ? "POST" : "DELETE",
    }),
  comments: (detailId: string) =>
    api<ApiComment[]>(`/details/${detailId}/comments`),
  addComment: (detailId: string, body: string, parentId?: string) =>
    api<ApiComment>(`/details/${detailId}/comments`, {
      method: "POST",
      body: { body, parent_id: parentId ? Number(parentId) : null },
    }),
  editComment: (commentId: string, body: string) =>
    api<ApiComment>(`/comments/${commentId}`, { method: "PUT", body: { body } }),
  deleteComment: (commentId: string) =>
    api(`/comments/${commentId}`, { method: "DELETE" }),
  report: (detailId: string, description: string) =>
    api(`/details/${detailId}/reports`, {
      method: "POST",
      body: { description },
    }),
};

// ---------- Colaboradores: carga de detalles y bandeja de aprobación ----------

export type StudioStatus =
  | "draft"
  | "pending"
  | "published"
  | "rejected"
  | "archived";
export type PlanSlug = "estudiante" | "pro";

export interface StudioDetailSummary {
  id: number;
  slug: string;
  title: string;
  status: StudioStatus;
  cover_url: string | null;
  category: string | null;
  required_plan: PlanSlug | null;
  is_verified: boolean;
  review_notes: string | null;
  author: { id: number; name: string; avatar_url: string | null } | null;
  updated_at: string;
  published_at: string | null;
}

export interface StudioDetail extends StudioDetailSummary {
  description: string;
  category_id: number;
  subcategory_id: number | null;
  specs: string[];
  materials: string[];
  tags: string[];
  images: { id: number; type: string; url: string; caption: string | null }[];
  files: { type: "dwg" | "skp"; original_name: string | null; size_bytes: number | null }[];
}

export interface StudioDetailInput {
  title: string;
  description: string;
  category_id: number | null;
  subcategory_id: number | null;
  specs: string[];
  materials: string[];
  tags: string[];
  required_plan: PlanSlug | null;
}

const fileForm = (field: string, file: File, extra: Record<string, string> = {}) => {
  const form = new FormData();
  form.append(field, file);
  Object.entries(extra).forEach(([k, v]) => form.append(k, v));
  return form;
};

export const StudioApi = {
  list: () => api<StudioDetailSummary[]>("/studio/details"),
  show: (id: number) => api<StudioDetail>(`/studio/details/${id}`),
  create: (data: StudioDetailInput) =>
    api<StudioDetail>("/studio/details", { method: "POST", body: data }),
  update: (id: number, data: StudioDetailInput) =>
    api<StudioDetail>(`/studio/details/${id}`, { method: "PUT", body: data }),
  submit: (id: number) =>
    api<StudioDetail>(`/studio/details/${id}/submit`, { method: "POST" }),
  remove: (id: number) => api(`/studio/details/${id}`, { method: "DELETE" }),
  uploadCover: (id: number, file: File) =>
    api<StudioDetail>(`/studio/details/${id}/cover`, {
      method: "POST",
      body: fileForm("image", file),
    }),
  addImage: (id: number, file: File, type: string, caption: string) =>
    api<StudioDetail>(`/studio/details/${id}/images`, {
      method: "POST",
      body: fileForm("image", file, { type, caption }),
    }),
  deleteImage: (id: number, imageId: number) =>
    api<StudioDetail>(`/studio/details/${id}/images/${imageId}`, {
      method: "DELETE",
    }),
  uploadFile: (id: number, type: "dwg" | "skp", file: File) =>
    api<StudioDetail>(`/studio/details/${id}/files/${type}`, {
      method: "POST",
      body: fileForm("file", file),
    }),
  deleteFile: (id: number, type: "dwg" | "skp") =>
    api<StudioDetail>(`/studio/details/${id}/files/${type}`, {
      method: "DELETE",
    }),
};

export const ReviewApi = {
  list: (status: "pending" | "published" | "rejected") =>
    api<{ details: StudioDetailSummary[]; pending_count: number }>(
      `/admin/review?status=${status}`,
    ),
  show: (id: number) => api<StudioDetail>(`/admin/review/${id}`),
  approve: (id: number, requiredPlan: PlanSlug | null, verified: boolean) =>
    api<StudioDetail>(`/admin/review/${id}/approve`, {
      method: "POST",
      body: { required_plan: requiredPlan, verified },
    }),
  reject: (id: number, notes: string) =>
    api<StudioDetail>(`/admin/review/${id}/reject`, {
      method: "POST",
      body: { notes },
    }),
  setVerified: (id: number, verified: boolean) =>
    api<StudioDetail>(`/admin/review/${id}/verify`, {
      method: verified ? "POST" : "DELETE",
    }),
};

// ---------- Panel admin ----------

export interface ApiErrorReport {
  id: number;
  detail: { id: number; title: string; slug: string } | null;
  user: { id: number; name: string } | null;
  description: string;
  status: "pending" | "resolved";
  created_at: string;
  resolved_at: string | null;
}

export interface AdminComment {
  id: number;
  body: string;
  created_at: string;
  updated_at: string;
  edited: boolean;
  is_reply: boolean;
  reply_to: string | null;
  user: { id: number; name: string; email: string; avatar_url: string | null } | null;
  detail: { id: number; title: string; slug: string } | null;
}
export interface AdminCommentsPage {
  total: number;
  current_page: number;
  last_page: number;
  comments: AdminComment[];
}
export interface AdminBackup {
  name: string;
  type: "manual" | "auto";
  size: number;
  created_at: string;
}
export interface AdminBackups {
  backups: AdminBackup[];
  auto_time: string;
  keep_auto: number;
  keep_manual: number;
}

export interface AdminStats {
  top_details: { id: number; title: string; slug: string; views: number }[];
  online_users: number;
  online_staff: number;
  new_users_7d: number;
  active_users: number;
  paid_subscriptions: number;
  monthly_revenue_ars: number;
  published_details: number;
  verified_details: number;
  pending_reports: number;
  pending_reviews: number;
  activity: { date: string; views: number }[];
  recent_users: {
    id: number;
    name: string;
    avatar_url: string | null;
    profession: string | null;
    plan: string;
  }[];
}

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  avatar_url: string | null;
  profession: string | null;
  company: string | null;
  status: "active" | "suspended";
  roles: string[];
  plan: { slug: "free" | PlanSlug; name: string };
  subscription: {
    provider: string | null;
    starts_at: string | null;
    ends_at: string | null;
  } | null;
  details_count: number;
  created_at: string;
}

export const AdminApi = {
  reports: () => api<ApiErrorReport[]>("/admin/reports"),
  resolveReport: (id: string) =>
    api<ApiErrorReport>(`/admin/reports/${id}/resolve`, { method: "PATCH" }),
  stats: () => api<AdminStats>("/admin/stats"),
  details: () => api<StudioDetailSummary[]>("/admin/details?status=all"),
  updateDetail: (
    id: number,
    status: "draft" | "published" | "archived",
    requiredPlan: PlanSlug | null,
  ) =>
    api<StudioDetail>(`/admin/details/${id}`, {
      method: "PATCH",
      body: { status, required_plan: requiredPlan },
    }),
  // "staff" = administrador + colaboradores
  users: (role: "usuario" | "colaborador" | "staff", q?: string) =>
    api<AdminUser[]>(
      `/admin/users?role=${role}${q ? `&q=${encodeURIComponent(q)}` : ""}`,
    ),
  updateUser: (
    id: number,
    data: {
      name: string;
      profession: string | null;
      company: string | null;
      status: "active" | "suspended";
      role: "usuario" | "colaborador";
    },
  ) => api<AdminUser>(`/admin/users/${id}`, { method: "PUT", body: data }),
  setUserPlan: (id: number, plan: "free" | PlanSlug, months: number | null) =>
    api<AdminUser>(`/admin/users/${id}/plan`, {
      method: "PUT",
      body: { plan, months },
    }),
  createUser: (data: {
    name: string;
    email: string;
    plan: "free" | PlanSlug;
    months: number | null;
    password: string | null;
    role?: "usuario" | "colaborador";
  }) =>
    api<AdminUser & { invite_sent: boolean | null }>("/admin/users", {
      method: "POST",
      body: data,
    }),
  updatePlan: (p: PricingPlan) =>
    api<ApiPlan[]>(`/admin/plans/${p.id}`, {
      method: "PUT",
      body: {
        name: p.name,
        description: p.description,
        features: p.features,
        price_ars: p.priceArs ?? 0,
        price_usd: p.priceUsd ?? 0,
        is_recommended: !!p.isRecommended,
      },
    }),
  comments: (q: string, page: number) =>
    api<AdminCommentsPage>(
      `/admin/comments?page=${page}${q ? `&q=${encodeURIComponent(q)}` : ""}`,
    ),
  backups: () => api<AdminBackups>("/admin/backups"),
  createBackup: () => api<AdminBackup>("/admin/backups", { method: "POST" }),
  deleteBackup: (name: string) =>
    api(`/admin/backups/${encodeURIComponent(name)}`, { method: "DELETE" }),
  backupUrl: (name: string) =>
    `${API_URL}/api/admin/backups/${encodeURIComponent(name)}/download`,
  createCategory: (data: {
    name: string;
    parent_id?: number | null;
    image_url?: string | null;
  }) => api<ApiCategory[]>("/admin/categories", { method: "POST", body: data }),
  updateDiscounts: (quarterly: number, yearly: number) =>
    api<ApiPlan[]>("/admin/billing/discounts", {
      method: "PUT",
      body: { quarterly, yearly },
    }),
  saveSettings: (c: SiteConfig) =>
    api<Record<string, any>>("/admin/settings", {
      method: "PUT",
      body: {
        site_title: c.siteTitle,
        home_title: c.homeTitle || null,
        home_subtitle: c.homeSubtitle || null,
        header_image_url: c.headerImageUrl || null,
        paywall_title: c.paywallTitle || null,
        paywall_description: c.paywallDescription || null,
        paywall_button_text: c.paywallButtonText || null,
        footer_text: c.footerText || null,
        address: c.address || null,
        terms_content: c.termsContent || null,
        privacy_content: c.privacyContent || null,
        maintenance_mode: !!c.maintenanceMode,
        social_links: c.socialLinks ?? {},
      },
    }),
};

export function toReport(r: ApiErrorReport): ErrorReport {
  return {
    id: String(r.id),
    detailId: r.detail ? String(r.detail.id) : "",
    detailTitle: r.detail?.title ?? "Detalle eliminado",
    description: r.description,
    status: r.status === "resolved" ? "Resolved" : "Pending",
    submittedAt: r.created_at.slice(0, 10),
  };
}

const relativeTime = new Intl.RelativeTimeFormat("es", { numeric: "auto" });

// "2 nov 2026, 14:35" (fecha y hora local de quien mira)
export function fullDate(iso: string): string {
  return new Date(iso).toLocaleString("es-AR", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

// "hace 5 minutos", "ayer", ... (más de un mes: la fecha)
export function timeAgo(iso: string): string {
  const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
  const abs = Math.abs(seconds);
  if (abs < 60) return "Ahora mismo";
  if (abs < 3600) return relativeTime.format(Math.round(seconds / 60), "minute");
  if (abs < 86400) return relativeTime.format(Math.round(seconds / 3600), "hour");
  if (abs < 86400 * 30)
    return relativeTime.format(Math.round(seconds / 86400), "day");
  return new Date(iso).toLocaleDateString("es-AR");
}

export function toComment(c: ApiComment, detailId: string): Comment {
  return {
    id: String(c.id),
    detailId,
    authorName: c.user.name,
    authorAvatar: c.user.avatar_url ?? DEFAULT_AVATAR,
    text: c.body,
    timestamp: timeAgo(c.created_at),
    role: c.badge ?? undefined,
    authorId: String(c.user.id),
    parentId: c.parent_id ? String(c.parent_id) : null,
    replies: c.replies.map((r) => toComment(r, detailId)),
    canDelete: c.can_delete,
    canEdit: c.can_edit,
    createdAt: c.created_at,
    edited: c.edited,
  };
}

export function toCategory(c: ApiCategory): CategoryDef {
  // El id es el slug porque el menú lateral lo usa para elegir el ícono.
  return {
    id: c.slug,
    name: c.name,
    imageUrl: c.image_url ?? "",
    subcategories: c.subcategories.map((s) => s.name),
    subcategorySlugs: Object.fromEntries(
      c.subcategories.map((s) => [s.name, s.slug]),
    ),
  };
}

export function toAuthor(a: NonNullable<ApiDetailCard["author"]>): Author {
  return {
    id: String(a.id),
    name: a.name,
    avatarUrl: a.avatar_url ?? DEFAULT_AVATAR,
    role: a.profession ?? "",
    team: a.company ?? undefined,
  };
}

export function toPlan(p: ApiPlan): PricingPlan {
  return {
    id: String(p.id),
    slug: p.slug,
    name: p.name,
    price: p.price,
    priceArs: p.price_ars,
    priceUsd: p.price_usd,
    periods: (p.periods ?? []).map((x) => ({
      id: x.id,
      months: x.months,
      discountPercent: x.discount_percent,
      totalArs: x.total_ars,
      totalUsd: x.total_usd,
      perMonthArs: x.per_month_ars,
      perMonthUsd: x.per_month_usd,
    })),
    interval: p.interval === "year" ? "yr" : "mo",
    description: p.description ?? "",
    features: p.features,
    isRecommended: p.is_recommended,
    highlightColor: p.highlight_color ?? undefined,
  };
}

export function toConfig(s: Record<string, any>): SiteConfig {
  return {
    siteTitle: s.site_title ?? "enDetalle",
    headerImageUrl: s.header_image_url ?? "",
    maintenanceMode: !!s.maintenance_mode,
    socialLinks: s.social_links ?? {},
    address: s.address,
    termsContent: s.terms_content,
    privacyContent: s.privacy_content,
    homeTitle: s.home_title,
    homeSubtitle: s.home_subtitle,
    footerText: s.footer_text,
    paywallTitle: s.paywall_title,
    paywallDescription: s.paywall_description,
    paywallButtonText: s.paywall_button_text,
  };
}
