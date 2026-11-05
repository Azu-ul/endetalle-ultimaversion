import { SiteConfig } from "./types";

// Foto de perfil cuando el usuario no subió una
export const DEFAULT_AVATAR =
  "https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?q=80&w=200&auto=format&fit=crop";

// Configuración mínima hasta que llega la de la API (/api/settings)
export const DEFAULT_CONFIG: SiteConfig = {
  siteTitle: "enDetalle",
  headerImageUrl: "",
  maintenanceMode: false,
  socialLinks: {},
};
