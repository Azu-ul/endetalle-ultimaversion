import React, { useEffect, useState } from "react";
import { useSearchParams } from "react-router";
import { AlertTriangle, CheckCircle2, Clock, Loader2 } from "lucide-react";
import { CheckoutApi, CheckoutOrder, errorText } from "../services/api";

interface PaymentResultPageProps {
  onPaid: () => void;
  onGoAccount: () => void;
  onGoPlans: () => void;
}

type Outcome =
  | { kind: "checking" }
  | { kind: "paid"; order: CheckoutOrder }
  | { kind: "pending" }
  | { kind: "failed" }
  | { kind: "error"; message: string };

const POLL_EVERY_MS = 3000;
const POLL_TIMES = 10;
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const formatDate = (iso: string | null) =>
  iso
    ? new Date(iso).toLocaleDateString("es-AR", { day: "numeric", month: "long", year: "numeric" })
    : null;

// Adonde vuelven Mercado Pago y PayPal después del checkout: confirma el pago contra el servidor
const PaymentResultPage: React.FC<PaymentResultPageProps> = ({ onPaid, onGoAccount, onGoPlans }) => {
  const [params] = useSearchParams();
  const [outcome, setOutcome] = useState<Outcome>({ kind: "checking" });
  const [verifying, setVerifying] = useState(false);
  const uuid = params.get("orden");

  useEffect(() => {
    let cancelled = false;
    const paypalToken = params.get("proveedor") === "paypal" ? params.get("token") : null;

    const run = async () => {
      if (!uuid) {
        setOutcome({ kind: "error", message: "No encontramos la compra. Revisá tu plan en Mi cuenta." });
        return;
      }
      try {
        // PayPal: al volver hay que capturar el pago aprobado
        let order = paypalToken ? await CheckoutApi.capture(uuid) : await CheckoutApi.order(uuid);

        // Mercado Pago puede tardar unos segundos en confirmar
        for (let i = 0; i < POLL_TIMES && order.status === "pending" && !cancelled; i++) {
          await sleep(POLL_EVERY_MS);
          order = await CheckoutApi.order(uuid);
        }
        if (cancelled) return;

        if (order.status === "paid") {
          setOutcome({ kind: "paid", order });
          onPaid();
        } else if (order.status === "pending") {
          setOutcome({ kind: "pending" });
        } else {
          setOutcome({ kind: "failed" });
        }
      } catch (err) {
        if (!cancelled)
          setOutcome({ kind: "error", message: errorText(err, "No pudimos confirmar el pago. Revisá tu plan en Mi cuenta.") });
      }
    };
    run();
    return () => {
      cancelled = true;
    };
  }, []);

  // "Ya pagué, verificar": se consulta a Mercado Pago por todas las compras sin acreditar
  const verifyAgain = async () => {
    if (!uuid || verifying) return;
    setVerifying(true);
    try {
      await CheckoutApi.sync();
      const order = await CheckoutApi.order(uuid);
      if (order.status === "paid") {
        setOutcome({ kind: "paid", order });
        onPaid();
      } else if (order.status !== "pending") {
        setOutcome({ kind: "failed" });
      } else {
        setOutcome({ kind: "pending" });
      }
    } catch (err) {
      setOutcome({ kind: "error", message: errorText(err, "No pudimos verificar el pago. Probá de nuevo en un rato.") });
    } finally {
      setVerifying(false);
    }
  };

  const buttonClass =
    "px-6 py-3 bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold rounded-xl shadow-lg";
  const secondaryClass =
    "px-6 py-3 font-bold text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-dark-bg hover:bg-slate-200 dark:hover:bg-slate-800 rounded-xl";

  return (
    <div className="max-w-lg mx-auto px-6 py-24 animate-in fade-in duration-500">
      <div className="bg-white dark:bg-dark-surface p-10 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm text-center">
        {outcome.kind === "checking" && (
          <>
            <Loader2 size={48} className="mx-auto mb-6 text-primary-500 animate-spin" />
            <h1 className="text-2xl font-bold dark:text-white mb-2">Confirmando tu pago...</h1>
            <p className="text-slate-500 dark:text-slate-400">Esto puede tardar unos segundos. No cierres esta página.</p>
          </>
        )}

        {outcome.kind === "paid" && (
          <>
            <CheckCircle2 size={48} className="mx-auto mb-6 text-green-500" />
            <h1 className="text-2xl font-bold dark:text-white mb-2">¡Pago acreditado!</h1>
            <p className="text-slate-500 dark:text-slate-400 mb-8">
              {outcome.order.scheduled
                ? `Tu plan ${outcome.order.plan.name} empieza el ${formatDate(outcome.order.starts_at)}, cuando vence el actual. Hasta entonces seguís con tu plan.`
                : `Tu plan ${outcome.order.plan.name} ya está activo${formatDate(outcome.order.ends_at) ? ` hasta el ${formatDate(outcome.order.ends_at)}` : ""}.`}{" "}
              Te mandamos el comprobante por email.
            </p>
            <button onClick={onGoAccount} className={buttonClass}>Ir a mi cuenta</button>
          </>
        )}

        {outcome.kind === "pending" && (
          <>
            <Clock size={48} className="mx-auto mb-6 text-amber-500" />
            <h1 className="text-2xl font-bold dark:text-white mb-2">Todavía no recibimos la confirmación</h1>
            <p className="text-slate-500 dark:text-slate-400 mb-8">
              Si ya pagaste, tu plan se activa solo en unos minutos y te avisamos por email. Podés revisar el estado en Mi cuenta.
            </p>
            <div className="flex flex-wrap justify-center gap-3">
              <button onClick={verifyAgain} disabled={verifying} className={`${buttonClass} disabled:opacity-50`}>
                {verifying ? "Verificando..." : "Ya pagué, verificar"}
              </button>
              <button onClick={onGoAccount} className={secondaryClass}>Ir a mi cuenta</button>
              <button onClick={onGoPlans} className={secondaryClass}>Volver a los planes</button>
            </div>
          </>
        )}

        {outcome.kind === "failed" && (
          <>
            <AlertTriangle size={48} className="mx-auto mb-6 text-red-500" />
            <h1 className="text-2xl font-bold dark:text-white mb-2">No pudimos confirmar el pago</h1>
            <p className="text-slate-500 dark:text-slate-400 mb-8">
              Esta compra no quedó acreditada. Si te cobraron, escribinos desde Contacto y lo resolvemos.
            </p>
            <div className="flex flex-wrap justify-center gap-3">
              <button onClick={verifyAgain} disabled={verifying} className={`${buttonClass} disabled:opacity-50`}>
                {verifying ? "Verificando..." : "Ya pagué, verificar"}
              </button>
              <button onClick={onGoPlans} className={secondaryClass}>Volver a los planes</button>
            </div>
          </>
        )}

        {outcome.kind === "error" && (
          <>
            <AlertTriangle size={48} className="mx-auto mb-6 text-red-500" />
            <h1 className="text-2xl font-bold dark:text-white mb-2">Algo salió mal</h1>
            <p className="text-slate-500 dark:text-slate-400 mb-8">{outcome.message}</p>
            <div className="flex flex-wrap justify-center gap-3">
              <button onClick={onGoAccount} className={buttonClass}>Ir a mi cuenta</button>
              <button onClick={onGoPlans} className={secondaryClass}>Volver a los planes</button>
            </div>
          </>
        )}
      </div>
    </div>
  );
};

export default PaymentResultPage;
