import React, { useEffect, useState } from "react";
import { CheckCircle2, ChevronDown, ChevronUp, ShieldCheck, X } from "lucide-react";
import {
  PlanSlug,
  ReviewApi,
  StudioDetail,
  StudioDetailSummary,
  errorText,
  timeAgo,
} from "../services/api";
import FormMessage from "./FormMessage";
import { PLAN_OPTIONS, STATUS_LABELS, formatBytes, imageTypeLabel } from "./studioLabels";

type Tab = "pending" | "published" | "rejected";
type Message = { type: "error" | "success"; text: string } | null;

const TABS: { id: Tab; label: string }[] = [
  { id: "pending", label: "Pendientes" },
  { id: "published", label: "Publicados" },
  { id: "rejected", label: "Con cambios pedidos" },
];

interface ReviewInboxProps {
  onPendingCount?: (count: number) => void;
  onChanged?: () => void; // para refrescar el explorador al publicar
}

// Bandeja de aprobación (panel admin): revisar, aprobar o pedir cambios, y verificación técnica
const ReviewInbox: React.FC<ReviewInboxProps> = ({ onPendingCount, onChanged }) => {
  const [tab, setTab] = useState<Tab>("pending");
  const [items, setItems] = useState<StudioDetailSummary[] | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);
  const [detail, setDetail] = useState<StudioDetail | null>(null);
  const [plan, setPlan] = useState<PlanSlug | null>(null);
  const [verified, setVerified] = useState(false);
  const [notes, setNotes] = useState("");
  const [rejecting, setRejecting] = useState(false);
  const [message, setMessage] = useState<Message>(null);
  const [busy, setBusy] = useState(false);

  const load = (t: Tab = tab) => {
    setItems(null);
    ReviewApi.list(t)
      .then((res) => {
        setItems(res.details);
        onPendingCount?.(res.pending_count);
      })
      .catch(() => setItems([]));
  };

  useEffect(() => {
    load(tab);
    setOpenId(null);
  }, [tab]);

  const toggleOpen = async (id: number) => {
    if (openId === id) {
      setOpenId(null);
      return;
    }
    setOpenId(id);
    setDetail(null);
    setMessage(null);
    setRejecting(false);
    setNotes("");
    try {
      const d = await ReviewApi.show(id);
      setDetail(d);
      setPlan(d.required_plan);
      setVerified(d.is_verified);
    } catch {
      setMessage({ type: "error", text: "No se pudo abrir el detalle." });
    }
  };

  const act = async (action: () => Promise<StudioDetail>, done: string) => {
    setBusy(true);
    setMessage(null);
    try {
      await action();
      setOpenId(null);
      load();
      onChanged?.();
      setMessage({ type: "success", text: done });
    } catch (err) {
      setMessage({ type: "error", text: errorText(err, "No se pudo completar la acción.") });
    } finally {
      setBusy(false);
    }
  };

  const toggleVerified = (d: StudioDetailSummary) =>
    act(
      () => ReviewApi.setVerified(d.id, !d.is_verified),
      d.is_verified ? "Se quitó la verificación técnica." : "Detalle marcado como verificado.",
    );

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div>
        <h2 className="text-2xl font-bold dark:text-white">Aprobaciones</h2>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          Detalles que cargan los colaboradores. Al aprobarlos se publican en el explorador.
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={`px-4 py-2 rounded-lg text-sm font-bold transition-colors ${
              tab === t.id ? "bg-primary-400 text-slate-900" : "bg-slate-100 dark:bg-dark-card text-slate-500 hover:text-slate-900 dark:hover:text-white"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {message && !openId && <FormMessage {...message} />}

      {items === null ? (
        <p className="text-sm text-slate-400">Cargando...</p>
      ) : items.length === 0 ? (
        <div className="bg-white dark:bg-dark-card p-10 rounded-2xl border border-slate-200 dark:border-dark-border text-center text-slate-500 dark:text-slate-400">
          {tab === "pending" ? "No hay detalles esperando revisión." : "No hay detalles en esta lista."}
        </div>
      ) : (
        <div className="space-y-4">
          {items.map((d) => (
            <div key={d.id} className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border overflow-hidden">
              <div className="p-4 flex items-center gap-4">
                <div className="w-14 h-[4.5rem] rounded-lg overflow-hidden bg-slate-100 dark:bg-dark-bg shrink-0">
                  {d.cover_url && <img src={d.cover_url} alt="" className="w-full h-full object-cover" />}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-bold text-slate-900 dark:text-white truncate">{d.title}</h3>
                    {d.is_verified && (
                      <span className="flex items-center gap-1 text-[10px] font-bold text-slate-900 bg-primary-400 px-2 py-0.5 rounded-full">
                        <CheckCircle2 size={10} /> VERIFICADO
                      </span>
                    )}
                  </div>
                  <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    {d.author?.name ?? "Sin autor"} · {d.category ?? "Sin categoría"} · {timeAgo(tab === "published" && d.published_at ? d.published_at : d.updated_at).toLowerCase()}
                  </p>
                </div>
                {tab === "published" ? (
                  <button
                    onClick={() => toggleVerified(d)}
                    disabled={busy}
                    className={`px-3 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 disabled:opacity-50 ${
                      d.is_verified ? "bg-slate-100 dark:bg-dark-bg text-slate-600 dark:text-slate-300 hover:bg-red-50 hover:text-red-600" : "bg-primary-400 text-slate-900 hover:bg-primary-500"
                    }`}
                  >
                    <ShieldCheck size={14} /> {d.is_verified ? "Quitar verificación" : "Verificar"}
                  </button>
                ) : (
                  <button
                    onClick={() => toggleOpen(d.id)}
                    className="px-3 py-2 rounded-lg text-xs font-bold bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 flex items-center gap-1"
                  >
                    {openId === d.id ? <ChevronUp size={14} /> : <ChevronDown size={14} />} Revisar
                  </button>
                )}
              </div>

              {tab === "rejected" && d.review_notes && openId !== d.id && (
                <p className="px-4 pb-4 text-sm text-red-600 dark:text-red-400">Pedido: {d.review_notes}</p>
              )}

              {openId === d.id && (
                <div className="border-t border-slate-100 dark:border-dark-border p-6 bg-slate-50 dark:bg-dark-bg/50 space-y-6">
                  {!detail ? (
                    message ? <FormMessage {...message} /> : <p className="text-sm text-slate-400">Cargando...</p>
                  ) : (
                    <>
                      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div className="lg:col-span-2 space-y-4">
                          <p className="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line">{detail.description}</p>
                          {detail.specs.length > 0 && (
                            <div>
                              <h4 className="text-xs font-bold text-slate-400 uppercase mb-2">Especificaciones</h4>
                              <ul className="list-disc pl-5 text-sm text-slate-700 dark:text-slate-300 space-y-1">
                                {detail.specs.map((s, i) => <li key={i}>{s}</li>)}
                              </ul>
                            </div>
                          )}
                          {detail.materials.length > 0 && (
                            <div>
                              <h4 className="text-xs font-bold text-slate-400 uppercase mb-2">Materiales</h4>
                              <ul className="list-disc pl-5 text-sm text-slate-700 dark:text-slate-300 space-y-1">
                                {detail.materials.map((s, i) => <li key={i}>{s}</li>)}
                              </ul>
                            </div>
                          )}
                          {detail.tags.length > 0 && (
                            <p className="text-xs text-slate-500">Etiquetas: {detail.tags.join(", ")}</p>
                          )}
                          {detail.images.length > 0 && (
                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                              {detail.images.map((img) => (
                                <a key={img.id} href={img.url} target="_blank" rel="noopener noreferrer" className="block rounded-lg overflow-hidden border border-slate-200 dark:border-dark-border">
                                  <img src={img.url} alt={img.caption ?? ""} className="w-full h-24 object-cover" />
                                  <p className="text-[11px] p-1.5 font-bold text-slate-600 dark:text-slate-300 bg-white dark:bg-dark-card">{imageTypeLabel(img.type)}</p>
                                </a>
                              ))}
                            </div>
                          )}
                          <p className="text-xs text-slate-500">
                            Archivos: {detail.files.length ? detail.files.map((f) => `${f.type.toUpperCase()} (${formatBytes(f.size_bytes)})`).join(", ") : "ninguno"}
                          </p>
                        </div>

                        <div className="bg-white dark:bg-dark-card p-5 rounded-2xl border border-slate-200 dark:border-dark-border space-y-4 h-fit">
                          <p className="text-xs font-bold text-slate-400 uppercase">
                            Estado: <span className={`px-2 py-0.5 rounded-full ${STATUS_LABELS[detail.status].className}`}>{STATUS_LABELS[detail.status].label}</span>
                          </p>
                          {detail.status === "rejected" && detail.review_notes && (
                            <p className="text-xs text-red-600 dark:text-red-400">Pedido anterior: {detail.review_notes}</p>
                          )}
                          {!rejecting ? (
                            <>
                              <div>
                                <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">Acceso</label>
                                <select
                                  value={detail.category === "Premium" ? "pro" : (plan ?? "")}
                                  disabled={detail.category === "Premium"}
                                  onChange={(e) => setPlan((e.target.value || null) as PlanSlug | null)}
                                  className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white text-sm"
                                >
                                  {PLAN_OPTIONS.map((p) => (
                                    <option key={p.id ?? "open"} value={p.id ?? ""}>{p.label}</option>
                                  ))}
                                </select>
                              </div>
                              <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 cursor-pointer">
                                <input type="checkbox" checked={verified} onChange={(e) => setVerified(e.target.checked)} className="w-4 h-4 accent-primary-400" />
                                Verificado técnicamente
                              </label>
                              <button
                                onClick={() => act(() => ReviewApi.approve(detail.id, plan, verified), `"${detail.title}" quedó publicado.`)}
                                disabled={busy}
                                className="w-full py-3 bg-green-500 hover:bg-green-600 text-white rounded-xl font-bold text-sm flex items-center justify-center gap-2 disabled:opacity-50"
                              >
                                <CheckCircle2 size={16} /> Aprobar y publicar
                              </button>
                              <button
                                onClick={() => setRejecting(true)}
                                disabled={busy}
                                className="w-full py-3 bg-slate-100 dark:bg-dark-bg text-red-600 hover:bg-red-50 rounded-xl font-bold text-sm flex items-center justify-center gap-2"
                              >
                                <X size={16} /> Pedir cambios
                              </button>
                            </>
                          ) : (
                            <>
                              <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">¿Qué tiene que corregir?</label>
                              <textarea
                                rows={4}
                                autoFocus
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white text-sm resize-none"
                              />
                              <div className="flex gap-2">
                                <button onClick={() => setRejecting(false)} className="flex-1 py-2.5 rounded-xl font-bold text-sm text-slate-500 hover:bg-slate-100 dark:hover:bg-dark-bg">
                                  Cancelar
                                </button>
                                <button
                                  onClick={() => act(() => ReviewApi.reject(detail.id, notes), "Se le pidieron cambios al colaborador.")}
                                  disabled={busy || !notes.trim()}
                                  className="flex-1 py-2.5 rounded-xl font-bold text-sm bg-red-500 hover:bg-red-600 text-white disabled:opacity-50"
                                >
                                  Enviar
                                </button>
                              </div>
                            </>
                          )}
                          {message && <FormMessage {...message} />}
                        </div>
                      </div>
                    </>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default ReviewInbox;
