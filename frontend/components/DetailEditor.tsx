import React, { useEffect, useRef, useState } from "react";
import { ArrowLeft, ImagePlus, Send, Trash2, Upload, X } from "lucide-react";
import {
  AdminApi,
  ApiCategory,
  PlanSlug,
  StudioApi,
  StudioDetail,
  StudioDetailInput,
  StudioDetailSummary,
  errorText,
} from "../services/api";
import FormMessage from "./FormMessage";
import { DEFAULT_AVATAR } from "../constants";
import {
  IMAGE_TYPES,
  PLAN_OPTIONS,
  STATUS_LABELS,
  formatBytes,
  imageTypeLabel,
} from "./studioLabels";

const cardClass =
  "bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm";
const labelClass = "block text-xs font-bold uppercase text-slate-500 mb-2";
const inputClass =
  "w-full bg-slate-50 dark:bg-dark-bg p-3 rounded-xl border border-slate-200 dark:border-dark-border dark:text-white disabled:opacity-70";
const primaryButtonClass =
  "px-6 py-3 bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold rounded-xl shadow-lg disabled:opacity-50 disabled:cursor-not-allowed";
const secondaryButtonClass =
  "px-6 py-3 font-bold text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-dark-bg hover:bg-slate-200 dark:hover:bg-slate-800 rounded-xl disabled:opacity-50";

const MAX_IMAGES = 12;
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
// Tiene que coincidir con DETAILS_MAX_MB del servidor (15 por defecto: el hosting limita las subidas a 16 MB)
const MAX_FILE_BYTES = 15 * 1024 * 1024;
const IMAGE_EXTENSIONS = ["jpg", "jpeg", "png", "webp"];

type Message = { type: "error" | "success"; text: string } | null;
type FileType = "dwg" | "skp";
type DetailStatus = StudioDetailSummary["status"];

interface FormState {
  title: string;
  description: string;
  category_id: number | null;
  subcategory_id: number | null;
  specsText: string;
  materialsText: string;
  tagsText: string;
  required_plan: PlanSlug | null;
}

const EMPTY_FORM: FormState = {
  title: "",
  description: "",
  category_id: null,
  subcategory_id: null,
  specsText: "",
  materialsText: "",
  tagsText: "",
  required_plan: null,
};

const lines = (text: string) =>
  text.split("\n").map((s) => s.trim()).filter(Boolean);

const toForm = (d: StudioDetail): FormState => ({
  title: d.title,
  description: d.description,
  category_id: d.category_id,
  subcategory_id: d.subcategory_id,
  specsText: d.specs.join("\n"),
  materialsText: d.materials.join("\n"),
  tagsText: d.tags.join(", "),
  required_plan: d.required_plan,
});

const toInput = (f: FormState): StudioDetailInput => ({
  title: f.title,
  description: f.description,
  category_id: f.category_id,
  subcategory_id: f.subcategory_id,
  specs: lines(f.specsText),
  materials: lines(f.materialsText),
  tags: f.tagsText.split(",").map((s) => s.trim()).filter(Boolean),
  required_plan: f.required_plan,
});

const extensionOf = (file: File) =>
  file.name.split(".").pop()?.toLowerCase() ?? "";

// Lo que se eligió en el formulario y todavía no se subió (se sube al guardar)
interface PendingImage {
  key: number;
  file: File;
  type: string;
  caption: string;
  preview: string;
}

export const StatusBadge: React.FC<{ status: DetailStatus }> = ({ status }) => (
  <span
    className={`text-[10px] px-2 py-1 rounded-full font-bold uppercase whitespace-nowrap ${STATUS_LABELS[status].className}`}
  >
    {STATUS_LABELS[status].label}
  </span>
);

interface DetailEditorProps {
  /** null = detalle nuevo */
  detailId: number | null;
  categories: ApiCategory[];
  /** El superadmin cambia el estado a mano y no envía a revisión */
  isAdmin?: boolean;
  backLabel: string;
  onBack: () => void;
  /** Se guardó algo: las listas de afuera tienen que recargarse */
  onChanged?: () => void;
  /** Si se pasa, se muestra quién subió el detalle con un botón para ir a su perfil */
  onOpenProfile?: (author: { id: number; name: string }) => void;
}

// Formulario único para cargar y editar detalles: datos, portada, láminas y archivos DWG/SKP
// se completan en la misma pantalla y se suben todos juntos al guardar.
const DetailEditor: React.FC<DetailEditorProps> = ({
  detailId,
  categories,
  isAdmin = false,
  backLabel,
  onBack,
  onChanged,
  onOpenProfile,
}) => {
  const [loading, setLoading] = useState(detailId !== null);
  const [editing, setEditing] = useState<StudioDetail | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [adminStatus, setAdminStatus] = useState<DetailStatus>("draft");
  const [message, setMessage] = useState<Message>(null);
  const [mediaMessage, setMediaMessage] = useState<Message>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const [coverFile, setCoverFile] = useState<File | null>(null);
  const [coverPreview, setCoverPreview] = useState<string | null>(null);
  const [newImages, setNewImages] = useState<PendingImage[]>([]);
  const [newFiles, setNewFiles] = useState<Partial<Record<FileType, File>>>({});
  const [imageType, setImageType] = useState(IMAGE_TYPES[0].id);
  const [imageCaption, setImageCaption] = useState("");

  const coverInput = useRef<HTMLInputElement>(null);
  const imageInput = useRef<HTMLInputElement>(null);
  const dwgInput = useRef<HTMLInputElement>(null);
  const skpInput = useRef<HTMLInputElement>(null);
  const imageKey = useRef(0);

  useEffect(() => {
    if (detailId === null) return;
    StudioApi.show(detailId)
      .then((d) => {
        setEditing(d);
        setForm(toForm(d));
        setAdminStatus(d.status);
      })
      .catch((err) =>
        setMessage({ type: "error", text: errorText(err, "No se pudo abrir el detalle.") }),
      )
      .finally(() => setLoading(false));
  }, [detailId]);

  // Las vistas previas locales se liberan al cambiar o salir
  useEffect(() => () => {
    if (coverPreview) URL.revokeObjectURL(coverPreview);
  }, [coverPreview]);
  const pendingPreviews = useRef<string[]>([]);
  pendingPreviews.current = newImages.map((i) => i.preview);
  useEffect(
    () => () => pendingPreviews.current.forEach((url) => URL.revokeObjectURL(url)),
    [],
  );

  const category = categories.find((c) => c.id === form.category_id);
  const isPremium = category?.slug === "premium";
  // Lo archivado solo lo toca el admin; lo publicado, un colaborador, lo reabre a revisión
  const editable = isAdmin || editing?.status !== "archived";
  const wasPublished = editing?.status === "published";
  const existingFile = (type: FileType) => editing?.files.find((f) => f.type === type);
  const imageCount = (editing?.images.length ?? 0) + newImages.length;
  const hasCover = !!coverFile || !!editing?.cover_url;

  // ---------- Elegir archivos (todavía no se suben) ----------
  const pick =
    (handler: (file: File) => void) => (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      e.target.value = ""; // permite volver a elegir el mismo archivo
      if (file) {
        setMediaMessage(null);
        handler(file);
      }
    };

  const checkImage = (file: File): boolean => {
    if (!IMAGE_EXTENSIONS.includes(extensionOf(file))) {
      setMediaMessage({ type: "error", text: "La imagen tiene que ser JPG, PNG o WebP." });
      return false;
    }
    if (file.size > MAX_IMAGE_BYTES) {
      setMediaMessage({ type: "error", text: "La imagen puede pesar hasta 5 MB." });
      return false;
    }
    return true;
  };

  const chooseCover = (file: File) => {
    if (!checkImage(file)) return;
    setCoverFile(file);
    setCoverPreview(URL.createObjectURL(file));
  };

  const chooseImage = (file: File) => {
    if (!checkImage(file)) return;
    setNewImages((list) => [
      ...list,
      {
        key: ++imageKey.current,
        file,
        type: imageType,
        caption: imageCaption.trim(),
        preview: URL.createObjectURL(file),
      },
    ]);
    setImageCaption("");
  };

  const removeNewImage = (key: number) =>
    setNewImages((list) => {
      const gone = list.find((i) => i.key === key);
      if (gone) URL.revokeObjectURL(gone.preview);
      return list.filter((i) => i.key !== key);
    });

  const chooseFile = (type: FileType) => (file: File) => {
    if (extensionOf(file) !== type) {
      setMediaMessage({ type: "error", text: `El archivo tiene que ser .${type.toUpperCase()}.` });
      return;
    }
    if (file.size > MAX_FILE_BYTES) {
      setMediaMessage({ type: "error", text: "El archivo puede pesar hasta 15 MB." });
      return;
    }
    setNewFiles((files) => ({ ...files, [type]: file }));
  };

  // ---------- Guardar ----------
  const handleSave = async (submit: boolean) => {
    setMessage(null);
    setMediaMessage(null);

    if (submit && !hasCover) {
      setMessage({ type: "error", text: "Subí una portada antes de enviar el detalle a revisión." });
      return;
    }
    if (isAdmin && adminStatus === "published" && !hasCover) {
      setMessage({ type: "error", text: "Para publicar, el detalle necesita una portada." });
      return;
    }

    setBusy(submit ? "submit" : "save");
    try {
      // 1. Datos del detalle (si es nuevo, se crea acá y ya queda con id)
      let d = editing
        ? await StudioApi.update(editing.id, toInput(form))
        : await StudioApi.create(toInput(form));
      setEditing(d);
      onChanged?.();

      // 2. Portada, láminas y archivos elegidos. Si algo falla, el resto sigue
      // y lo que falló queda en el formulario para reintentar con Guardar.
      const failures: string[] = [];
      const upload = async (label: string, action: (id: number) => Promise<StudioDetail>) => {
        try {
          d = await action(d.id);
          return true;
        } catch (err) {
          failures.push(`${label}: ${errorText(err, "no se pudo subir")}`);
          return false;
        }
      };

      if (coverFile && (await upload("Portada", (id) => StudioApi.uploadCover(id, coverFile)))) {
        setCoverFile(null);
        setCoverPreview(null);
      }

      const stillPending: PendingImage[] = [];
      for (const img of newImages) {
        const ok = await upload(`Lámina ${img.file.name}`, (id) =>
          StudioApi.addImage(id, img.file, img.type, img.caption),
        );
        if (ok) URL.revokeObjectURL(img.preview);
        else stillPending.push(img);
      }
      setNewImages(stillPending);

      for (const type of ["dwg", "skp"] as const) {
        const file = newFiles[type];
        if (file && (await upload(type.toUpperCase(), (id) => StudioApi.uploadFile(id, type, file)))) {
          setNewFiles((files) => ({ ...files, [type]: undefined }));
        }
      }

      setEditing(d);
      if (failures.length > 0) {
        setMessage({
          type: "error",
          text: `El detalle se guardó, pero no se pudo subir: ${failures.join(" · ")}. Corregilo y volvé a guardar.`,
        });
        onChanged?.();
        return;
      }

      // 3. Estado
      if (isAdmin) {
        if (adminStatus !== d.status && ["draft", "published", "archived"].includes(adminStatus)) {
          d = await AdminApi.updateDetail(
            d.id,
            adminStatus as "draft" | "published" | "archived",
            form.required_plan,
          );
        }
      } else if (submit) {
        d = await StudioApi.submit(d.id);
      }

      setEditing(d);
      setForm(toForm(d));
      setAdminStatus(d.status);
      onChanged?.();
      setMessage({
        type: "success",
        text: isAdmin
          ? "Cambios guardados."
          : submit
            ? "Enviado a revisión. Se publica cuando lo apruebe el equipo de enDetalle."
            : "Cambios guardados.",
      });
    } catch (err) {
      setMessage({ type: "error", text: errorText(err, "No se pudo guardar. Probá de nuevo.") });
    } finally {
      setBusy(null);
    }
  };

  const handleDelete = async () => {
    if (!editing || !window.confirm("¿Eliminar este detalle? No se puede deshacer.")) return;
    try {
      await StudioApi.remove(editing.id);
      onChanged?.();
      onBack();
    } catch (err) {
      setMessage({ type: "error", text: errorText(err, "No se pudo eliminar. Probá de nuevo.") });
    }
  };

  // Quitar lo que ya está subido se hace en el momento
  const removeUploaded = async (key: string, action: () => Promise<StudioDetail>) => {
    setBusy(key);
    setMediaMessage(null);
    try {
      setEditing(await action());
      onChanged?.();
    } catch (err) {
      setMediaMessage({ type: "error", text: errorText(err, "No se pudo quitar. Probá de nuevo.") });
    } finally {
      setBusy(null);
    }
  };

  if (loading) return <p className="text-sm text-slate-400">Cargando...</p>;
  if (detailId !== null && !editing) {
    return (
      <div className={cardClass}>
        <button onClick={onBack} className="flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-primary-500 mb-4">
          <ArrowLeft size={16} /> {backLabel}
        </button>
        {message && <FormMessage {...message} />}
      </div>
    );
  }

  const coverShown = coverPreview ?? editing?.cover_url ?? null;
  const statusOptions: DetailStatus[] = ["draft", "published", "archived"];

  return (
    <div className="space-y-6">
      <div className={cardClass}>
        <button
          onClick={onBack}
          className="flex items-center gap-2 text-sm font-bold text-slate-500 dark:text-slate-400 hover:text-primary-500 mb-4"
        >
          <ArrowLeft size={16} /> {backLabel}
        </button>
        <div className="flex flex-wrap items-center gap-3 mb-6">
          <h2 className="text-2xl font-bold dark:text-white">
            {editing ? editing.title : "Nuevo detalle"}
          </h2>
          {editing && <StatusBadge status={editing.status} />}
        </div>

        {editing?.author && onOpenProfile && (
          <div className="mb-6">
            <p className="text-xs font-bold uppercase text-slate-400 mb-2">Subido por</p>
            <button
              type="button"
              onClick={() => onOpenProfile(editing.author!)}
              className="flex items-center gap-4 bg-white dark:bg-dark-card p-4 pr-6 rounded-2xl border border-slate-200 dark:border-dark-border hover:shadow-lg transition-all text-left"
            >
              <img
                src={editing.author.avatar_url || DEFAULT_AVATAR}
                alt=""
                className="w-14 h-14 rounded-full object-cover"
              />
              <span>
                <span className="block font-bold text-lg dark:text-white">{editing.author.name}</span>
                <span className="block text-sm text-primary-600 dark:text-primary-400 font-bold">Ver perfil →</span>
              </span>
            </button>
          </div>
        )}

        {editing?.status === "rejected" && editing.review_notes && (
          <div className="mb-6 p-4 rounded-2xl bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/50">
            <p className="text-xs font-bold uppercase text-red-600 dark:text-red-400 mb-1">
              Cambios pedidos por el equipo
            </p>
            <p className="text-sm text-red-700 dark:text-red-300 whitespace-pre-line">
              {editing.review_notes}
            </p>
          </div>
        )}
        {editing?.status === "pending" && !isAdmin && (
          <p className="mb-6 text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 p-4 rounded-2xl">
            Está en revisión. Podés seguir corrigiéndolo mientras tanto.
          </p>
        )}
        {wasPublished && !isAdmin && (
          <p className="mb-6 text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 p-4 rounded-2xl">
            Ya está publicado. Si lo editás, vuelve a revisión y deja de verse en el sitio hasta que el equipo lo apruebe.
          </p>
        )}
        {editing?.status === "archived" && !isAdmin && (
          <p className="mb-6 text-sm text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-dark-bg p-4 rounded-2xl">
            Está archivado: no se muestra en el explorador. Para cambiarlo, escribile al administrador.
          </p>
        )}

        <div className="space-y-6">
          <div>
            <label className={labelClass}>Título</label>
            <input
              disabled={!editable}
              value={form.title}
              onChange={(e) => setForm({ ...form, title: e.target.value })}
              placeholder="Ej: Encuentro de muro doble con losa"
              className={inputClass}
            />
          </div>
          <div>
            <label className={labelClass}>Descripción</label>
            <textarea
              rows={4}
              disabled={!editable}
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              className={`${inputClass} resize-y`}
            />
          </div>
          <div className={`grid grid-cols-1 gap-6 ${isAdmin ? "md:grid-cols-4" : "md:grid-cols-3"}`}>
            {isAdmin && (
              <div>
                <label className={labelClass}>Estado</label>
                <select
                  value={adminStatus}
                  onChange={(e) => setAdminStatus(e.target.value as DetailStatus)}
                  className={inputClass}
                >
                  {/* "En revisión" y "Con cambios pedidos" solo se muestran (se resuelven en Aprobaciones) */}
                  {editing && ["pending", "rejected"].includes(editing.status) && (
                    <option value={editing.status}>{STATUS_LABELS[editing.status].label}</option>
                  )}
                  {statusOptions.map((s) => (
                    <option key={s} value={s}>
                      {STATUS_LABELS[s].label}
                    </option>
                  ))}
                </select>
              </div>
            )}
            <div>
              <label className={labelClass}>Categoría</label>
              <select
                disabled={!editable}
                value={form.category_id ?? ""}
                onChange={(e) =>
                  setForm({
                    ...form,
                    category_id: e.target.value ? Number(e.target.value) : null,
                    subcategory_id: null,
                  })
                }
                className={inputClass}
              >
                <option value="">Elegí una categoría</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className={labelClass}>Subcategoría</label>
              <select
                disabled={!editable || !category?.subcategories.length}
                value={form.subcategory_id ?? ""}
                onChange={(e) =>
                  setForm({
                    ...form,
                    subcategory_id: e.target.value ? Number(e.target.value) : null,
                  })
                }
                className={inputClass}
              >
                <option value="">Ninguna</option>
                {category?.subcategories.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className={labelClass}>{isAdmin ? "Acceso" : "Acceso sugerido"}</label>
              <select
                disabled={!editable || isPremium}
                value={isPremium ? "pro" : (form.required_plan ?? "")}
                onChange={(e) =>
                  setForm({ ...form, required_plan: (e.target.value || null) as PlanSlug | null })
                }
                className={inputClass}
              >
                {PLAN_OPTIONS.map((p) => (
                  <option key={p.id ?? "open"} value={p.id ?? ""}>
                    {p.label}
                  </option>
                ))}
              </select>
              {isPremium && (
                <p className="text-xs text-slate-400 mt-1">La categoría Premium es solo para Pro.</p>
              )}
            </div>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label className={labelClass}>Especificaciones (una por línea)</label>
              <textarea
                rows={5}
                disabled={!editable}
                value={form.specsText}
                onChange={(e) => setForm({ ...form, specsText: e.target.value })}
                className={`${inputClass} resize-y`}
              />
            </div>
            <div>
              <label className={labelClass}>Materiales (uno por línea)</label>
              <textarea
                rows={5}
                disabled={!editable}
                value={form.materialsText}
                onChange={(e) => setForm({ ...form, materialsText: e.target.value })}
                className={`${inputClass} resize-y`}
              />
            </div>
          </div>
          <div>
            <label className={labelClass}>Etiquetas (separadas por coma)</label>
            <input
              disabled={!editable}
              value={form.tagsText}
              onChange={(e) => setForm({ ...form, tagsText: e.target.value })}
              placeholder="Ej: ladrillo, aislación, humedad"
              className={inputClass}
            />
          </div>
        </div>
      </div>

      {/* Imágenes y archivos: se eligen acá y se suben junto con el resto al guardar */}
      <div className={cardClass}>
        <h3 className="text-xl font-bold dark:text-white mb-1">Imágenes y archivos</h3>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          Elegí lo que quieras adjuntar: todo se sube junto con el detalle cuando apretás guardar.
        </p>
        <div className="space-y-8 mt-6">
          {mediaMessage && <FormMessage {...mediaMessage} />}

          {/* Portada */}
          <div className="flex flex-col sm:flex-row gap-6">
            <div className="w-32 aspect-[9/16] rounded-xl overflow-hidden bg-slate-100 dark:bg-dark-bg border border-slate-200 dark:border-dark-border shrink-0 flex items-center justify-center text-slate-300">
              {coverShown ? (
                <img src={coverShown} alt="Portada" className="w-full h-full object-cover" />
              ) : (
                <ImagePlus size={28} />
              )}
            </div>
            <div>
              <p className={labelClass}>Portada</p>
              <p className="text-sm text-slate-500 dark:text-slate-400 mb-3">
                La imagen de la tarjeta del explorador (vertical, 9:16). Obligatoria para enviar a revisión o publicar.
              </p>
              {editable && (
                <button
                  type="button"
                  disabled={busy !== null}
                  onClick={() => coverInput.current?.click()}
                  className={`${secondaryButtonClass} flex items-center gap-2 text-sm`}
                >
                  <Upload size={16} /> {hasCover ? "Cambiar portada" : "Elegir portada"}
                </button>
              )}
              {coverFile && (
                <p className="text-xs text-slate-400 mt-2">
                  {coverFile.name} · se sube al guardar
                </p>
              )}
            </div>
          </div>

          {/* Láminas */}
          <div>
            <p className={labelClass}>Láminas ({imageCount} de {MAX_IMAGES})</p>
            {(editing?.images.length ?? 0) + newImages.length > 0 && (
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-4">
                {editing?.images.map((img) => (
                  <div key={img.id} className="rounded-xl overflow-hidden border border-slate-200 dark:border-dark-border bg-slate-50 dark:bg-dark-bg">
                    <img src={img.url} alt={img.caption ?? ""} className="w-full h-28 object-cover" />
                    <div className="p-2 flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="text-xs font-bold text-slate-700 dark:text-slate-200">{imageTypeLabel(img.type)}</p>
                        {img.caption && <p className="text-xs text-slate-500 truncate">{img.caption}</p>}
                      </div>
                      {editable && (
                        <button
                          type="button"
                          title="Quitar lámina"
                          disabled={busy !== null}
                          onClick={() => removeUploaded(`img-${img.id}`, () => StudioApi.deleteImage(editing.id, img.id))}
                          className="text-slate-400 hover:text-red-500 shrink-0"
                        >
                          <Trash2 size={14} />
                        </button>
                      )}
                    </div>
                  </div>
                ))}
                {newImages.map((img) => (
                  <div key={img.key} className="rounded-xl overflow-hidden border border-dashed border-primary-400 bg-slate-50 dark:bg-dark-bg">
                    <img src={img.preview} alt={img.caption} className="w-full h-28 object-cover" />
                    <div className="p-2 flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="text-xs font-bold text-slate-700 dark:text-slate-200">{imageTypeLabel(img.type)}</p>
                        <p className="text-xs text-slate-500 truncate">{img.caption || "Se sube al guardar"}</p>
                      </div>
                      <button
                        type="button"
                        title="Quitar lámina"
                        disabled={busy !== null}
                        onClick={() => removeNewImage(img.key)}
                        className="text-slate-400 hover:text-red-500 shrink-0"
                      >
                        <X size={14} />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}
            {editable && imageCount < MAX_IMAGES && (
              <div className="flex flex-col md:flex-row gap-3 md:items-end">
                <div className="md:w-48">
                  <label className="block text-xs text-slate-500 mb-1">Tipo</label>
                  <select value={imageType} onChange={(e) => setImageType(e.target.value)} className={inputClass}>
                    {IMAGE_TYPES.map((t) => (
                      <option key={t.id} value={t.id}>{t.label}</option>
                    ))}
                  </select>
                </div>
                <div className="flex-1">
                  <label className="block text-xs text-slate-500 mb-1">Epígrafe (opcional)</label>
                  <input value={imageCaption} onChange={(e) => setImageCaption(e.target.value)} className={inputClass} />
                </div>
                <button
                  type="button"
                  disabled={busy !== null}
                  onClick={() => imageInput.current?.click()}
                  className={`${secondaryButtonClass} flex items-center gap-2 text-sm`}
                >
                  <ImagePlus size={16} /> Agregar lámina
                </button>
              </div>
            )}
            <p className="text-xs text-slate-400 mt-2">JPG, PNG o WebP, hasta 5 MB cada una.</p>
          </div>

          {/* Archivos descargables */}
          <div>
            <p className={labelClass}>Archivos descargables</p>
            <div className="space-y-2">
              {(["dwg", "skp"] as const).map((type) => {
                const saved = existingFile(type);
                const chosen = newFiles[type];
                const input = type === "dwg" ? dwgInput : skpInput;
                return (
                  <div key={type} className="flex items-center gap-4 p-3 rounded-xl bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border">
                    <span className="text-xs font-bold bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 px-2 py-1 rounded uppercase">{type}</span>
                    <span className="flex-1 min-w-0 text-sm text-slate-600 dark:text-slate-300 truncate">
                      {chosen
                        ? `${chosen.name} · ${formatBytes(chosen.size)} · se sube al guardar`
                        : saved
                          ? `${saved.original_name ?? "archivo"} · ${formatBytes(saved.size_bytes)}`
                          : type === "dwg"
                            ? "Plano editable de AutoCAD (opcional)"
                            : "Modelo 3D de SketchUp (opcional)"}
                    </span>
                    {editable && (
                      <>
                        <button
                          type="button"
                          disabled={busy !== null}
                          onClick={() => input.current?.click()}
                          className="text-sm font-bold text-primary-600 dark:text-primary-400 hover:underline disabled:opacity-50"
                        >
                          {chosen || saved ? "Reemplazar" : "Elegir archivo"}
                        </button>
                        {chosen ? (
                          <button
                            type="button"
                            title="Descartar archivo elegido"
                            disabled={busy !== null}
                            onClick={() => setNewFiles((files) => ({ ...files, [type]: undefined }))}
                            className="text-slate-400 hover:text-red-500"
                          >
                            <X size={14} />
                          </button>
                        ) : (
                          saved && (
                            <button
                              type="button"
                              title="Quitar archivo"
                              disabled={busy !== null}
                              onClick={() => removeUploaded(type, () => StudioApi.deleteFile(editing!.id, type))}
                              className="text-slate-400 hover:text-red-500"
                            >
                              <Trash2 size={14} />
                            </button>
                          )
                        )}
                      </>
                    )}
                  </div>
                );
              })}
            </div>
            <p className="text-xs text-slate-400 mt-2">Hasta 15 MB por archivo. Se descargan según el plan de cada usuario.</p>
          </div>

          <input ref={coverInput} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={pick(chooseCover)} />
          <input ref={imageInput} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={pick(chooseImage)} />
          <input ref={dwgInput} type="file" accept=".dwg" hidden onChange={pick(chooseFile("dwg"))} />
          <input ref={skpInput} type="file" accept=".skp" hidden onChange={pick(chooseFile("skp"))} />
        </div>

        {editable && (
          <div className="flex flex-wrap items-center justify-end gap-3 pt-6 mt-8 border-t border-slate-100 dark:border-dark-border">
            {message && (
              <div className="mr-auto">
                <FormMessage {...message} />
              </div>
            )}
            {!isAdmin && editing && ["draft", "rejected"].includes(editing.status) && (
              <button
                type="button"
                onClick={handleDelete}
                disabled={busy !== null}
                className="px-4 py-3 font-bold text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 rounded-xl disabled:opacity-50"
              >
                Eliminar
              </button>
            )}
            {isAdmin ? (
              <button
                type="button"
                onClick={() => handleSave(false)}
                disabled={busy !== null}
                className={primaryButtonClass}
              >
                {busy === "save" ? "Guardando..." : "Guardar"}
              </button>
            ) : (
              <>
                {!wasPublished && (
                  <button
                    type="button"
                    onClick={() => handleSave(false)}
                    disabled={busy !== null}
                    className={secondaryButtonClass}
                  >
                    {busy === "save"
                      ? "Guardando..."
                      : editing?.status === "pending"
                        ? "Guardar cambios"
                        : "Guardar borrador"}
                  </button>
                )}
                {editing?.status !== "pending" && (
                  <button
                    type="button"
                    onClick={() => handleSave(true)}
                    disabled={busy !== null}
                    className={`${primaryButtonClass} flex items-center gap-2`}
                  >
                    <Send size={16} />{" "}
                    {busy === "submit"
                      ? "Enviando..."
                      : wasPublished
                        ? "Guardar y enviar a revisión"
                        : "Enviar a revisión"}
                  </button>
                )}
              </>
            )}
          </div>
        )}
        {!editable && message && (
          <div className="mt-6">
            <FormMessage {...message} />
          </div>
        )}
      </div>
    </div>
  );
};

export default DetailEditor;
