import React, { useEffect, useState } from "react";
import { Search } from "lucide-react";
import { AdminOrder, AdminOrdersApi, errorText } from "../services/api";
import FormMessage from "./FormMessage";

const PERIOD_LABELS: Record<string, string> = { monthly: "Mensual", quarterly: "3 meses", yearly: "Anual" };
const STATUS: Record<string, { label: string; className: string }> = {
  paid: { label: "Pagada", className: "bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300" },
  pending: { label: "Pendiente", className: "bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300" },
  refunded: { label: "Reembolsada", className: "bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300" },
  expired: { label: "Vencida", className: "bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400" },
  cancelled: { label: "Cancelada", className: "bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400" },
  failed: { label: "Fallida", className: "bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300" },
};
const KIND_LABELS = { upgrade: "Subida de plan", queued: "Bajada encolada" } as const;

const money = (amount: number, currency: string) =>
  new Intl.NumberFormat("es-AR", { style: "currency", currency, maximumFractionDigits: currency === "ARS" ? 0 : 2 }).format(amount);
const dateTime = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString("es-AR", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—";

const selectClass =
  "p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl text-sm dark:text-white";

// Órdenes y pagos: solo lectura, para ver quién pagó qué, con qué medio y cuándo
const AdminOrders: React.FC = () => {
  const [orders, setOrders] = useState<AdminOrder[] | null>(null);
  const [status, setStatus] = useState("all");
  const [provider, setProvider] = useState("all");
  const [term, setTerm] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // Con un pequeño retraso al escribir para no pedir en cada letra
    const timer = setTimeout(() => {
      AdminOrdersApi.list({ status, provider, q: term.trim() })
        .then((res) => {
          setOrders(res);
          setError(null);
        })
        .catch((err) => {
          setOrders([]);
          setError(errorText(err, "No se pudieron cargar las órdenes."));
        });
    }, 250);
    return () => clearTimeout(timer);
  }, [status, provider, term]);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h2 className="text-2xl font-bold dark:text-white">Pagos y órdenes</h2>
        <div className="flex flex-wrap gap-3">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
            <input
              value={term}
              onChange={(e) => setTerm(e.target.value)}
              placeholder="Buscar por usuario o email"
              className={`${selectClass} pl-9`}
            />
          </div>
          <select value={status} onChange={(e) => setStatus(e.target.value)} className={selectClass}>
            <option value="all">Todos los estados</option>
            {Object.entries(STATUS).map(([id, s]) => (
              <option key={id} value={id}>{s.label}</option>
            ))}
          </select>
          <select value={provider} onChange={(e) => setProvider(e.target.value)} className={selectClass}>
            <option value="all">Todos los medios</option>
            <option value="mercadopago">Mercado Pago</option>
            <option value="paypal">PayPal</option>
          </select>
        </div>
      </div>

      {error && <FormMessage type="error" text={error} />}

      <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border overflow-x-auto">
        <table className="w-full text-left">
          <thead className="bg-slate-50 dark:bg-dark-bg border-b border-slate-200 dark:border-dark-border">
            <tr>
              {["Orden", "Usuario", "Plan", "Monto", "Estado", "Medio de pago", "ID del pago", "Fecha"].map((h) => (
                <th key={h} className="p-4 text-xs font-bold text-slate-500 uppercase whitespace-nowrap">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-dark-border">
            {orders?.map((o) => (
              <tr key={o.id}>
                <td className="p-4 text-sm font-bold dark:text-white">#{o.id}</td>
                <td className="p-4 text-sm">
                  <p className="font-bold dark:text-white">{o.user.name}</p>
                  <p className="text-xs text-slate-500">{o.user.email}</p>
                </td>
                <td className="p-4 text-sm text-slate-600 dark:text-slate-300">
                  {o.plan} · {PERIOD_LABELS[o.period] ?? o.period}
                  {o.kind && <span className="block text-[10px] uppercase font-bold text-primary-600">{KIND_LABELS[o.kind]}</span>}
                </td>
                <td className="p-4 text-sm font-bold dark:text-white whitespace-nowrap">{money(o.amount, o.currency)}</td>
                <td className="p-4">
                  <span className={`text-[10px] px-2 py-1 rounded-full font-bold uppercase ${STATUS[o.status]?.className ?? ""}`}>
                    {STATUS[o.status]?.label ?? o.status}
                  </span>
                </td>
                <td className="p-4 text-sm text-slate-600 dark:text-slate-300">{o.provider === "paypal" ? "PayPal" : "Mercado Pago"}</td>
                <td className="p-4 text-xs text-slate-500 break-all">{o.provider_payment_id ?? "—"}</td>
                <td className="p-4 text-sm text-slate-600 dark:text-slate-300 whitespace-nowrap">{dateTime(o.paid_at ?? o.created_at)}</td>
              </tr>
            ))}
            {orders === null && (
              <tr><td colSpan={8} className="p-8 text-center text-sm text-slate-400">Cargando...</td></tr>
            )}
            {orders?.length === 0 && (
              <tr><td colSpan={8} className="p-8 text-center text-sm text-slate-400 italic">No hay órdenes con esos filtros.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};

export default AdminOrders;
