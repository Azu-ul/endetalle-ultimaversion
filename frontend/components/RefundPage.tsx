import React, { useEffect, useState } from "react";
import { CheckCircle2, Clock, MailWarning } from "lucide-react";
import { RefundApi, RefundOrder, errorText } from "../services/api";
import FormMessage from "./FormMessage";

const PERIOD_LABELS: Record<string, string> = { monthly: "1 mes", quarterly: "3 meses", yearly: "12 meses" };

const money = (amount: number, currency: string) =>
  new Intl.NumberFormat("es-AR", { style: "currency", currency, maximumFractionDigits: currency === "ARS" ? 0 : 2 }).format(amount);

const date = (iso: string) =>
  new Date(iso).toLocaleDateString("es-AR", { day: "numeric", month: "long", year: "numeric" });

const labelClass = "block text-xs font-bold uppercase text-slate-500 mb-2";
const inputClass =
  "w-full bg-slate-50 dark:bg-dark-bg p-3 rounded-xl border border-slate-200 dark:border-dark-border dark:text-white";


// "Botón de arrepentimiento": el usuario elige una compra pagada dentro del plazo y pide el reembolso
const RefundPage: React.FC<{ onBack: () => void }> = ({ onBack }) => {
  const [orders, setOrders] = useState<RefundOrder[] | null>(null);
  const [windowDays, setWindowDays] = useState(15);
  const [selected, setSelected] = useState<number | null>(null);
  const [reason, setReason] = useState("");
  const [phone, setPhone] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<{ code: string } | null>(null);

  useEffect(() => {
    RefundApi.list()
      .then((res) => {
        setOrders(res.orders);
        setWindowDays(res.window_days);
        setSelected(res.orders.find((o) => o.can_request)?.id ?? null);
      })
      .catch((err) => {
        setOrders([]);
        setError(errorText(err, "No se pudieron cargar tus compras."));
      });
  }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (selected === null) return;
    setSending(true);
    setError(null);
    try {
      const res = await RefundApi.request({ order_id: selected, reason: reason.trim() || undefined, phone: phone.trim() || undefined });
      setDone({ code: res.code });
    } catch (err) {
      setError(errorText(err, "No se pudo enviar la solicitud. Probá de nuevo."));
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="max-w-2xl mx-auto px-6 py-16 animate-in fade-in duration-500">
      <div className="bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm">
        {done ? (
          <div className="text-center">
            <CheckCircle2 size={48} className="mx-auto mb-6 text-green-500" />
            <h1 className="text-2xl font-bold dark:text-white mb-2">Recibimos tu solicitud</h1>
            <p className="text-slate-500 dark:text-slate-400 mb-4">Tu código es</p>
            <p className="text-3xl font-bold text-primary-600 dark:text-primary-400 mb-6">{done.code}</p>
            <p className="text-sm text-slate-500 dark:text-slate-400 mb-8">
              Te mandamos un email con el código y los datos de la compra. Revisamos la solicitud y, si corresponde, te devolvemos el dinero
              por el mismo medio de pago. Tu plan sigue activo hasta que se apruebe el reembolso.
            </p>
            <div className="flex items-start gap-3 text-left bg-amber-50 dark:bg-amber-500/10 border border-amber-300 dark:border-amber-500/40 rounded-2xl p-4 mb-8">
              <MailWarning size={22} className="shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
              <p className="text-sm text-amber-900 dark:text-amber-100">
                <strong>Revisá tu carpeta de Spam.</strong> Los emails de esta solicitud (la confirmación y, más adelante, la aprobación del
                reembolso con su comprobante) pueden llegar a <strong>Spam</strong>, <strong>Promociones</strong> o correo no deseado. Si lo
                encontrás ahí, marcalo como "No es spam" para no perderte los próximos avisos.
              </p>
            </div>
            <button onClick={onBack} className="px-6 py-3 bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold rounded-xl shadow-lg">
              Volver
            </button>
          </div>
        ) : (
          <>
            <h1 className="text-2xl font-bold dark:text-white mb-2">Botón de arrepentimiento</h1>
            <p className="text-sm text-slate-500 dark:text-slate-400 mb-8">
              Podés pedir el reembolso de una compra dentro de los {windowDays} días corridos desde la fecha de pago. Pasado ese plazo no se
              reembolsa. Cada compra se evalúa por separado y el plan se corta cuando aprobamos el reembolso.
            </p>

            {orders === null ? (
              <p className="text-sm text-slate-400">Cargando...</p>
            ) : orders.length === 0 ? (
              <p className="text-sm text-slate-500 dark:text-slate-400 italic py-6 text-center">Todavía no tenés compras pagadas.</p>
            ) : (
              <form onSubmit={submit} className="space-y-6">
                <div>
                  <p className={labelClass}>Elegí la compra</p>
                  <div className="space-y-3">
                    {orders.map((o) => (
                      <label
                        key={o.id}
                        className={`flex items-start gap-4 p-4 rounded-2xl border transition-colors ${
                          o.can_request
                            ? selected === o.id
                              ? "border-primary-400 bg-primary-50 dark:bg-primary-400/10 cursor-pointer"
                              : "border-slate-200 dark:border-dark-border hover:border-primary-400 cursor-pointer"
                            : "border-slate-200 dark:border-dark-border bg-slate-50 dark:bg-dark-bg opacity-70"
                        }`}
                      >
                        <input
                          type="radio"
                          name="order"
                          disabled={!o.can_request}
                          checked={selected === o.id}
                          onChange={() => setSelected(o.id)}
                          className="mt-1.5 accent-primary-500"
                        />
                        <div className="flex-1 min-w-0">
                          <p className="font-bold text-slate-900 dark:text-white">
                            Plan {o.plan} · {PERIOD_LABELS[o.period] ?? o.period} · {money(o.amount, o.currency)}
                          </p>
                          <p className="text-xs text-slate-500 dark:text-slate-400">
                            Pagada el {date(o.paid_at)} con {o.provider === "paypal" ? "PayPal" : "Mercado Pago"}
                          </p>
                          {o.can_request ? (
                            <p className="text-xs text-green-600 dark:text-green-400 mt-1 flex items-center gap-1">
                              <Clock size={12} /> Podés pedirlo hasta el {date(o.deadline)}
                            </p>
                          ) : o.order_status === "refunded" || o.refund?.status === "approved" ? (
                            <p className="text-xs font-bold text-green-600 dark:text-green-400 mt-1">Reembolsada</p>
                          ) : o.refund?.status === "pending" ? (
                            <p className="text-xs text-amber-600 dark:text-amber-400 mt-1">Solicitud pendiente ({o.refund.code})</p>
                          ) : o.refund?.status === "rejected" ? (
                            <div className="text-xs text-red-500 mt-1 space-y-0.5">
                              <p>Solicitud rechazada ({o.refund.code}). Si querés volver a consultarlo, escribinos desde Contacto</p>
                              {o.refund.rejection_reason && <p>Motivo: {o.refund.rejection_reason}</p>}
                            </div>
                          ) : (
                            <p className="text-xs text-red-500 mt-1">{o.message}</p>
                          )}
                        </div>
                      </label>
                    ))}
                  </div>
                </div>

                <div>
                  <label className={labelClass}>Motivo (opcional)</label>
                  <textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} className={`${inputClass} resize-y`} />
                </div>
                <div>
                  <label className={labelClass}>Teléfono de contacto (opcional)</label>
                  <input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Ej: +54 9 223 555-1234" className={inputClass} />
                </div>

                <div className="flex items-start gap-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-300 dark:border-amber-500/40 rounded-xl p-4">
                  <MailWarning size={20} className="shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
                  <p className="text-sm text-amber-900 dark:text-amber-100">
                    Te vamos a avisar por email. Esos mensajes pueden llegar a tu carpeta de <strong>Spam</strong>, así que revisala después de
                    enviar la solicitud.
                  </p>
                </div>

                {error && <FormMessage type="error" text={error} />}
                <button
                  type="submit"
                  disabled={selected === null || sending}
                  className="px-6 py-3 bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold rounded-xl shadow-lg disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {sending ? "Enviando..." : "Solicitar reembolso"}
                </button>
                {selected === null && !orders.some((o) => o.can_request) && (
                  <p className="text-sm text-slate-500 dark:text-slate-400">No tenés compras que se puedan reembolsar en este momento.</p>
                )}
              </form>
            )}
          </>
        )}
      </div>
    </div>
  );
};

export default RefundPage;
