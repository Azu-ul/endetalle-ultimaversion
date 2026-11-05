import { Author, CategoryDef } from "./types";

// Rutas fijas de cada pantalla
export const PATHS = {
  HOME: "/",
  SUBSCRIPTION: "/planes",
  CONTACT: "/contacto",
  AUTHORS: "/autores",
  LEGAL_TERMS: "/terminos",
  LEGAL_PRIVACY: "/privacidad",
  AUTH: "/ingresar",
  RESET_PASSWORD: "/restablecer",
  PAYMENT_RESULT: "/pago/resultado",
  REFUND: "/arrepentimiento",
  USER_DASHBOARD: "/mi-cuenta",
  ADMIN: "/admin",
  MESSAGES: "/mensajes",
} as const;

export type StaticView = keyof typeof PATHS;

// "Juan Pérez & Cía." -> "juan-perez-cia"
export const slugify = (s: string) =>
  s
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

export const detailPath = (slug: string) =>
  `/detalle/${encodeURIComponent(slug)}`;

// El id manda; el nombre va de adorno para que el link se lea lindo
export const profilePath = (author: Author) =>
  `/perfil/${author.id}-${slugify(author.name)}`;

// Ruta de una categoría (y opcionalmente una subcategoría), por nombre
export function categoryPath(
  categories: CategoryDef[],
  catName: string,
  subName?: string,
): string | null {
  const cat = categories.find((c) => c.name === catName);
  if (!cat) return null;
  if (!subName) return `/categoria/${cat.id}`;
  const subSlug = cat.subcategorySlugs?.[subName];
  return subSlug ? `/categoria/${cat.id}/${subSlug}` : null;
}

// Link absoluto para compartir
export const absoluteUrl = (path: string) => window.location.origin + path;

// Solo se aceptan rutas internas para volver después de ingresar
export function safeReturnPath(value: string | null): string | null {
  if (!value || !value.startsWith("/") || value.startsWith("//")) return null;
  return value;
}
