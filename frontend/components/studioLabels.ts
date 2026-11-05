import type { PlanSlug, StudioStatus } from "../services/api";

// Textos y colores compartidos por "Mis detalles" (colaboradores) y la bandeja de aprobación (admin)

export const STATUS_LABELS: Record<StudioStatus, { label: string; className: string }> = {
  draft: {
    label: "Borrador",
    className: "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300",
  },
  pending: {
    label: "En revisión",
    className: "bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300",
  },
  published: {
    label: "Publicado",
    className: "bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300",
  },
  rejected: {
    label: "Con cambios pedidos",
    className: "bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300",
  },
  archived: {
    label: "Archivado",
    className: "bg-slate-200 text-slate-500 dark:bg-slate-800 dark:text-slate-400",
  },
};

export const IMAGE_TYPES: { id: string; label: string }[] = [
  { id: "section", label: "Corte" },
  { id: "plan", label: "Planta" },
  { id: "elevation", label: "Vista" },
  { id: "axonometric", label: "Axonometría" },
  { id: "detail", label: "Detalle" },
  { id: "photo", label: "Foto de obra" },
];

export const imageTypeLabel = (id: string) =>
  IMAGE_TYPES.find((t) => t.id === id)?.label ?? id;

export const PLAN_OPTIONS: { id: PlanSlug | null; label: string }[] = [
  { id: null, label: "Abierto (cualquier cuenta, también Free)" },
  { id: "estudiante", label: "Estudiante o superior" },
  { id: "pro", label: "Solo Pro" },
];

export const formatBytes = (bytes: number | null) => {
  if (!bytes) return "";
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};
