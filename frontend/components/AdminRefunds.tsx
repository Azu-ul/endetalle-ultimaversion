import React, { useEffect, useState } from "react";
import { ArrowLeft, Copy, Download, MessageCircle } from "lucide-react";
import {
  AdminRefund,
  AdminRefundApi,
  RefundStatus,
  downloadRefundReceipt,
  errorText,
} from "../services/api";
import FormMessage from "./FormMessage";

const PERIOD_LABELS: Record<string, string> = { monthly: "Mensual", quarterly: "3 meses", yearly: "Anual" };
const STATUS: Record<RefundStatus, { label: string; className: string }> = {
  pending: { label: "Pendiente", className: "bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300" },
  approved: { label: "Aprobado", className: "bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300" },
  rejected: { label: "Rechazado", className: "bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300" },
};
const FILTERS: { id: RefundStatus | "all"; label: string }[] = [
  { id: "pending", label: "Pendientes" },
  { id: "approved", label: "Aprobados" },
  { id: "rejected", label: "Rechazados" },
  { id: "all", label: "Todos" },
];

const money = (amount: number, currency: string) =>
  new Intl.NumberFormat("es-AR", { style: "currency", currency, maximumFractionDigits: currency === "ARS" ? 0 : 2 }).format(amount);
const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("es-AR", { day: "numeric", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";
const date = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString("es-AR", { day: "numeric", month: "long", year: "numeric" }) : "—";

const fieldClass =
  "w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl text-sm dark:text-white";

type Message = { type: "error" | "success"; text: string } | null;

const Row: React.FC<{ label: string; children: React.ReactNode }> = ({ label, children }) => (
  <div className="flex justify-between gap-4 border-b border-slate-100 dark:border-dark-border py-2.5 text-sm">
    <span className="text-slate-500 dark:text-slate-400">{label}</span>
    <span className="font-bold dark:text-white text-right break-all">{children}</span>
  </div>
);

interface AdminRefundsProps {
  onPendingCount?: (count: number) => void;
}

// Reembolsos manuales: el admin revisa la solicitud, devuelve el dinero a mano y adjunta el comprobante
const AdminRefunds: React.FC<AdminRefundsProps> = ({ onPendingCount }) => {
  const [filter, setFilter] = useState<RefundStatus | "all">("pending");
  const [list, setList] = useState<AdminRefund[] | null>(null);
  const [selected, setSelected] = useState<AdminRefund | null>(null);
  const [message, setMessage] = useState<Message>(null);
  const [copied, setCopied] = useState(false);
  const [receipt, setReceipt] = useState<File | null>(null);
  const [note, setNote] = useState("");
  const [rejectReason, setRejectReason] = useState("");
  const [busy, setBusy] = useState<"approve" | "reject" | null>(null);

  const load = (status = filter) =>
    AdminRefundApi.list(status)
      .then((res) => {
        setList(res.requests);
        onPendingCount?.(res.pending_count);
      })
      .catch((err) => {
        setList([]);
        setMessage({ type: "error", text: errorText(err, "No se pudieron cargar los reembolsos.") });
      });

  useEffect(() => {
    setList(null);
    load(filter);
  }, [filter]);

  const open = (r: AdminRefund) => {
    setSelected(r);
    setMessage(null);
    setReceipt(null);
    setNote("");
    setRejectReason("");
  };

  const copyEmail = async (email: string) => {
    try {
      await navigator.clipboard.writeText(email);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setMessage({ type: "error", text: "No se pudo copiar el email." });
    }
  };

  const whatsappUrl = (r: AdminRefund) => {
    const digits = (r.phone ?? "").replace(/\D/g, "");
    const text = `Hola ${r.name}, te escribimos de enDetalle por tu solicitud de reembolso ${r.code}.`;
    return `https://wa.me/${digits}?text=${encodeURIComponent(text)}`;
  };

  const approve = async () => {
    if (!selected || !receipt) {
      setMessage({ type: "error", text: "Adjuntá el comprobante del reembolso (PDF o imagen)." });
      return;
    }
    if (!window.confirm(`¿Aprobar el reembolso ${selected.code}? El usuario vuelve al plan Free.`)) return;
    setBusy("approve");
    setMessage(null);
    try {
      const updated = await AdminRefundApi.approve(selected.id, receipt, note);
      setSelected(updated);
      setMessage({ type: "success", text: "Reembolso aprobado: el plan se cortó y le mandamos el comprobante por email." });
      load();
    } catch (err) {
      setMessage({ type: "error", text: errorText(err, "No se pudo aprobar el reembolso.") });
    } finally {
      setBusy(null);
    }
  };

  const reject = async () => {
    if (!selected) return;
    if (rejectReason.trim().length < 5) {
      setMessage({ type: "error", text: "Escribí el motivo del rechazo: se lo mandamos al usuario." });
      return;
    }
    setBusy("reject");
    setMessage(null);
    try {
      const updated = await AdminRefundApi.reject(selected.id, rejectReason.trim());
      setSelected(updated);
      setMessage({ type: "success", text: "Solicitud rechazada: le avisamos al usuario." });
      load();
    } catch (err) {
      setMessage({ type: "error", text: errorText(err, "No se pudo rechazar la solicitud.") });
    } finally {
      setBusy(null);
    }
  };

  const downloadReceipt = async (id: number) => {
    try {
      await downloadRefundReceipt(id);
    } catch (err) {
      setMessage({ type: "error", text: errorText(err, "No se pudo descargar el comprobante.") });
    }
  };

  // ---------- Detalle ----------
  if (selected) {
    const r = selected;
    return (
      <div className="max-w-4xl mx-auto space-y-6">
        <button
          onClick={() => {
            setSelected(null);
            setMessage(null);
          }}
          className="flex items-center gap-2 text-sm font-bold text-slate-500 dark:text-slate-400 hover:text-primary-500"
        >
          <ArrowLeft size={16} /> Reembolsos
        </button>

        <div className="flex flex-wrap items-center gap-3">
          <h2 className="text-2xl font-bold dark:text-white">{r.code}</h2>
          <span className={`text-[10px] px-2 py-1 rounded-full font-bold uppercase ${STATUS[r.status].className}`}>{STATUS[r.status].label}</span>
        </div>

        {message && <FormMessage {...message} />}

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border">
            <h3 className="font-bold mb-2 dark:text-white">Solicitante</h3>
            <Row label="Fecha de solicitud">{dateTime(r.requested_at)}</Row>
            <Row label="Nombre">{r.name}</Row>
            <Row label="Email">{r.email}</Row>
            <Row label="Teléfono">{r.phone ?? "—"}</Row>
            {r.reason && <Row label="Motivo">{r.reason}</Row>}
            <div className="flex flex-wrap gap-2 mt-4">
              <button
                onClick={() => copyEmail(r.email)}
                className="flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-100 dark:bg-dark-bg text-sm font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-800"
              >
                <Copy size={14} /> {copied ? "¡Copiado!" : "Copiar email"}
              </button>
              {r.phone && (
                <a
                  href={whatsappUrl(r)}
                  target="_blank"
                  rel="noreferrer"
                  className="flex items-center gap-2 px-3 py-2 rounded-xl bg-green-100 dark:bg-green-950 text-sm font-bold text-green-700 dark:text-green-300 hover:bg-green-200"
                >
                  <MessageCircle size={14} /> Abrir WhatsApp
                </a>
              )}
            </div>
          </div>

          <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border">
            <h3 className="font-bold mb-2 dark:text-white">Compra</h3>
            <Row label="Plan">{r.plan}</Row>
            <Row label="Período">{PERIOD_LABELS[r.period] ?? r.period}</Row>
            <Row label="Monto pagado">{money(r.order_amount, r.currency)}</Row>
            <Row label={`A devolver (${r.refund_percent}%)`}>{money(r.amount, r.currency)}</Row>
            <Row label="Medio de pago">{r.provider === "paypal" ? "PayPal" : "Mercado Pago"}</Row>
            <Row label="ID del pago">{r.provider_payment_id ?? "—"}</Row>
            <Row label="Fecha de pago">{dateTime(r.paid_at)}</Row>
            <Row label="Días desde el pago">{r.days_since_payment}</Row>
            <Row label="Pedido dentro de plazo">
              <span className={r.within_window ? "text-green-600" : "text-red-500"}>{r.within_window ? "Sí" : "No"}</span>
            </Row>
          </div>
        </div>

        {r.status === "pending" ? (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border space-y-4">
              <h3 className="font-bold dark:text-white">Aprobar</h3>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                Devolvé el dinero a mano desde {r.provider === "paypal" ? "PayPal" : "Mercado Pago"}, adjuntá el comprobante y aprobá. El plan se corta y el usuario recibe el comprobante por email.
              </p>
              <div>
                <label className="block text-xs font-bold uppercase text-slate-500 mb-2">Comprobante (PDF o imagen, hasta 5 MB)</label>
                <input
                  type="file"
                  accept=".pdf,image/jpeg,image/png,image/webp"
                  onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
                  className="block w-full text-sm text-slate-600 dark:text-slate-300 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-slate-100 file:text-slate-700 file:font-bold"
                />
              </div>
              <textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} placeholder="Nota (opcional)" className={fieldClass} />
              <button
                onClick={approve}
                disabled={busy !== null}
                className="px-5 py-2.5 bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold rounded-xl disabled:opacity-50"
              >
                {busy === "approve" ? "Aprobando..." : "Aprobar reembolso"}
              </button>
            </div>

            <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border space-y-4">
              <h3 className="font-bold dark:text-white">Rechazar</h3>
              <p className="text-xs text-slate-500 dark:text-slate-400">El motivo se lo mandamos al usuario por email. El plan no se toca.</p>
              <textarea rows={3} value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} maxLength={1000} placeholder="Motivo del rechazo" className={fieldClass} />
              <button
                onClick={reject}
                disabled={busy !== null}
                className="px-5 py-2.5 bg-red-50 hover:bg-red-100 dark:bg-red-950/30 text-red-600 font-bold rounded-xl disabled:opacity-50"
              >
                {busy === "reject" ? "Rechazando..." : "Rechazar solicitud"}
              </button>
            </div>
          </div>
        ) : (
          <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border">
            <h3 className="font-bold mb-2 dark:text-white">Resolución</h3>
            <Row label="Resuelta por">{r.reviewed_by ?? "—"}</Row>
            <Row label="Fecha">{dateTime(r.reviewed_at)}</Row>
            {r.admin_note && <Row label="Nota">{r.admin_note}</Row>}
            {r.rejection_reason && <Row label="Motivo del rechazo">{r.rejection_reason}</Row>}
            {r.has_receipt && (
              <button
                onClick={() => downloadReceipt(r.id)}
                className="mt-4 flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-100 dark:bg-dark-bg text-sm font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-800"
              >
                <Download size={14} /> Descargar comprobante
              </button>
            )}
          </div>
        )}
      </div>
    );
  }

  // ---------- Listado ----------
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h2 className="text-2xl font-bold dark:text-white">Reembolsos</h2>
        <div className="inline-flex p-1 rounded-xl bg-slate-100 dark:bg-dark-bg">
          {FILTERS.map((f) => (
            <button
              key={f.id}
              onClick={() => setFilter(f.id)}
              aria-pressed={filter === f.id}
              className={`px-4 py-2 rounded-lg text-sm font-bold transition-all ${
                filter === f.id ? "bg-white dark:bg-dark-surface text-slate-900 dark:text-white shadow-sm" : "text-slate-500 dark:text-slate-400"
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>

      {message && <FormMessage {...message} />}

      <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border overflow-x-auto">
        <table className="w-full text-left">
          <thead className="bg-slate-50 dark:bg-dark-bg border-b border-slate-200 dark:border-dark-border">
            <tr>
              {["Código", "Solicitada", "Nombre", "Plan", "Monto", "Medio", "Estado"].map((h) => (
                <th key={h} className="p-4 text-xs font-bold text-slate-500 uppercase whitespace-nowrap">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-dark-border">
            {list?.map((r) => (
              <tr key={r.id} onClick={() => open(r)} className="hover:bg-slate-50 dark:hover:bg-dark-bg/50 cursor-pointer">
                <td className="p-4 font-bold dark:text-white whitespace-nowrap">{r.code}</td>
                <td className="p-4 text-sm text-slate-600 dark:text-slate-300 whitespace-nowrap">{date(r.requested_at)}</td>
                <td className="p-4 text-sm dark:text-white">{r.name}</td>
                <td className="p-4 text-sm text-slate-600 dark:text-slate-300">{r.plan}</td>
                <td className="p-4 text-sm font-bold dark:text-white whitespace-nowrap">{money(r.amount, r.currency)}</td>
                <td className="p-4 text-sm text-slate-600 dark:text-slate-300">{r.provider === "paypal" ? "PayPal" : "Mercado Pago"}</td>
                <td className="p-4">
                  <span className={`text-[10px] px-2 py-1 rounded-full font-bold uppercase ${STATUS[r.status].className}`}>{STATUS[r.status].label}</span>
                </td>
              </tr>
            ))}
            {list === null && (
              <tr><td colSpan={7} className="p-8 text-center text-sm text-slate-400">Cargando...</td></tr>
            )}
            {list?.length === 0 && (
              <tr><td colSpan={7} className="p-8 text-center text-sm text-slate-400 italic">No hay solicitudes en esta lista.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default AdminRefunds;
