
import React, { useEffect, useState } from 'react';
import { CheckCircle2, X, Zap, Crown, GraduationCap, ShieldCheck, Download, Users, ArrowDown, HelpCircle, Star } from 'lucide-react';
import { ClientUser, PricingPlan } from '../types';
import { CheckoutApi, CheckoutStatus } from '../services/api';

type PeriodId = 'monthly' | 'quarterly' | 'yearly';

interface SubscriptionPageProps {
  plans: PricingPlan[];
  currentUser?: ClientUser | null;
  // Devuelve true si se está redirigiendo al pago (el botón queda esperando)
  onSubscribe: (plan: PricingPlan, periodId: PeriodId, currency: 'ARS' | 'USD') => Promise<boolean> | boolean;
}

const formatDate = (iso: string) =>
  new Date(iso).toLocaleDateString('es-AR', { day: 'numeric', month: 'long', year: 'numeric' });

const SubscriptionPage: React.FC<SubscriptionPageProps> = ({ plans, currentUser, onSubscribe }) => {
  // Qué puede hacer el usuario con cada plan (renovar, subir, bloqueado) según el servidor
  const [purchase, setPurchase] = useState<CheckoutStatus | null>(null);
  useEffect(() => {
    if (!currentUser) {
      setPurchase(null);
      return;
    }
    CheckoutApi.status().then(setPurchase).catch(() => setPurchase(null));
  }, [currentUser?.id, currentUser?.planSlug, currentUser?.subscriptionEndsAt]);

  // Moneda (pesos con Mercado Pago, dólares con PayPal) y período de pago elegidos
  const [currency, setCurrency] = useState<'ARS' | 'USD'>('ARS');
  const [periodId, setPeriodId] = useState<PeriodId>('monthly');
  const [payingPlan, setPayingPlan] = useState<string | null>(null);

  const handleSubscribe = async (plan: PricingPlan) => {
    if (plan.price === 0 || payingPlan) return;
    setPayingPlan(plan.id);
    const redirecting = await onSubscribe(plan, periodId, currency);
    if (!redirecting) setPayingPlan(null);
  };

  const money = (n: number) =>
    new Intl.NumberFormat('es-AR', {
      style: 'currency',
      currency,
      maximumFractionDigits: currency === 'ARS' || Number.isInteger(n) ? 0 : 2,
    }).format(n);

  // Descuento de cada período, tomado de los planes que vienen de la API
  const discountOf = (id: string) =>
    plans.flatMap((p) => p.periods ?? []).find((p) => p.id === id)?.discountPercent ?? 0;

  const PERIOD_OPTIONS: { id: 'monthly' | 'quarterly' | 'yearly'; label: string }[] = [
    { id: 'monthly', label: 'Mensual' },
    { id: 'quarterly', label: '3 meses' },
    { id: 'yearly', label: 'Anual' },
  ];

  const renderPrice = (plan: PricingPlan) => {
    const muted = plan.isRecommended ? 'text-slate-600' : 'text-slate-400';
    const period = plan.periods?.find((p) => p.id === periodId);

    // Plan gratuito (o plan sin períodos): se muestra el precio tal cual
    if (plan.price === 0 || !period) {
      return (
        <div className="mb-6">
          <span className={`text-4xl font-bold ${plan.isRecommended ? 'text-slate-900' : 'text-white'}`}>{money(plan.price === 0 ? 0 : plan.price)}</span>
          <span className={`text-sm ${muted}`}>/mes</span>
          <div className="mt-1 h-5" />
        </div>
      );
    }

    const perMonth = currency === 'ARS' ? period.perMonthArs : period.perMonthUsd;
    const total = currency === 'ARS' ? period.totalArs : period.totalUsd;

    return (
      <div className="mb-6">
        <div>
          <span className={`text-4xl font-bold ${plan.isRecommended ? 'text-slate-900' : 'text-white'}`}>{money(perMonth)}</span>
          <span className={`text-sm ${muted}`}>/mes</span>
        </div>
        <div className={`flex items-center gap-2 text-xs mt-1 h-5 ${muted}`}>
          <span>
            {period.months === 1
              ? 'Se cobra cada mes'
              : `${money(total)} cada ${period.months === 12 ? 'año' : `${period.months} meses`}`}
          </span>
          {period.discountPercent > 0 && (
            <span className={`font-bold px-2 py-0.5 rounded-full ${plan.isRecommended ? 'bg-green-100 text-green-700' : 'bg-green-500/20 text-green-400'}`}>
              -{period.discountPercent}%
            </span>
          )}
        </div>
      </div>
    );
  };

  const scrollToPlans = () => {
    document.getElementById('pricing-section')?.scrollIntoView({ behavior: 'smooth' });
  };

  return (
    <div className="animate-in fade-in duration-700">
        
        {/* HERO SECTION - FUNNEL TOP */}
        <div className="relative py-20 lg:py-32 bg-slate-900 overflow-hidden -mx-6 md:-mx-10 -mt-6 md:-mt-10 mb-16">
            <div className="absolute inset-0 opacity-20 bg-[url('https://images.unsplash.com/photo-1487958449943-2429e8be8625?q=80&w=2000&auto=format&fit=crop')] bg-cover bg-center" />
            <div className="absolute inset-0 bg-gradient-to-b from-transparent to-slate-900" />
            
            <div className="max-w-7xl mx-auto px-6 lg:px-8 relative z-10 text-center">
                <div className="inline-flex items-center gap-2 bg-primary-500/10 text-primary-400 px-4 py-2 rounded-full border border-primary-500/20 text-sm font-bold uppercase tracking-widest mb-6 backdrop-blur-md">
                    <ShieldCheck size={16} /> Verificado por Expertos
                </div>
                <h1 className="text-5xl lg:text-7xl font-bold text-white mb-8 leading-tight tracking-tight">
                    Domina el Arte de <br/>
                    <span className="text-transparent bg-clip-text bg-gradient-to-r from-primary-400 to-primary-600">Construir Mejor</span>
                </h1>
                <p className="text-xl text-slate-400 max-w-2xl mx-auto mb-12 leading-relaxed">
                    Accede a la biblioteca más completa de detalles constructivos, normativas y recursos técnicos. Ahorra cientos de horas en documentación.
                </p>
                <div className="flex flex-col sm:flex-row gap-4 justify-center">
                    <button onClick={scrollToPlans} className="bg-primary-400 hover:bg-primary-500 text-slate-900 text-lg font-bold px-8 py-4 rounded-xl shadow-xl shadow-primary-400/20 hover:scale-105 transition-all">
                        Ver Planes y Precios
                    </button>
                    <button className="bg-white/10 hover:bg-white/20 text-white text-lg font-bold px-8 py-4 rounded-xl backdrop-blur-sm border border-white/10 transition-colors">
                        Explorar Gratis
                    </button>
                </div>
                
                <div className="mt-16 flex justify-center gap-8 lg:gap-16 text-slate-500">
                    <div className="flex flex-col items-center">
                        <span className="text-3xl font-bold text-white">+10k</span>
                        <span className="text-sm font-medium">Arquitectos</span>
                    </div>
                    <div className="flex flex-col items-center">
                        <span className="text-3xl font-bold text-white">500+</span>
                        <span className="text-sm font-medium">Detalles DWG</span>
                    </div>
                </div>
            </div>
        </div>

        {/* FEATURES GRID - WHY US */}
        <div className="max-w-7xl mx-auto px-6 mb-24">
            <div className="text-center mb-16">
                <h2 className="text-3xl font-bold text-slate-900 dark:text-white mb-4">¿Por qué elegir enDetalle?</h2>
                <p className="text-slate-500 dark:text-slate-400">Todo lo que necesitas para profesionalizar tus entregas.</p>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div className="bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm">
                    <div className="w-12 h-12 bg-primary-50 dark:bg-primary-900/20 text-primary-500 rounded-xl flex items-center justify-center mb-6">
                        <CheckCircle2 size={24} />
                    </div>
                    <h3 className="text-xl font-bold text-slate-900 dark:text-white mb-3">Técnicamente Verificado</h3>
                    <p className="text-slate-500 dark:text-slate-400 leading-relaxed">
                        Cada detalle es revisado por ingenieros y arquitectos senior. Cumplimiento normativo asegurado.
                    </p>
                </div>
                <div className="bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm">
                    <div className="w-12 h-12 bg-blue-50 dark:bg-blue-900/20 text-blue-500 rounded-xl flex items-center justify-center mb-6">
                        <Download size={24} />
                    </div>
                    <h3 className="text-xl font-bold text-slate-900 dark:text-white mb-3">Formatos Editables</h3>
                    <p className="text-slate-500 dark:text-slate-400 leading-relaxed">
                        Descarga directa en DWG y SketchUp. Listos para insertar en tus planos sin marcas de agua.
                    </p>
                </div>
                <div className="bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm">
                    <div className="w-12 h-12 bg-purple-50 dark:bg-purple-900/20 text-purple-500 rounded-xl flex items-center justify-center mb-6">
                        <Users size={24} />
                    </div>
                    <h3 className="text-xl font-bold text-slate-900 dark:text-white mb-3">Comunidad Activa</h3>
                    <p className="text-slate-500 dark:text-slate-400 leading-relaxed">
                        Comenta y debate soluciones de obra con otros arquitectos y estudiantes de la comunidad.
                    </p>
                </div>
            </div>
        </div>

        {/* TESTIMONIALS - SOCIAL PROOF */}
        <div className="bg-slate-50 dark:bg-dark-bg/50 py-20 mb-24 -mx-6 md:-mx-10 px-6 md:px-10">
             <div className="max-w-7xl mx-auto">
                 <h2 className="text-3xl font-bold text-center text-slate-900 dark:text-white mb-12">Lo que dicen los profesionales</h2>
                 <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                     <div className="bg-white dark:bg-dark-card p-8 rounded-2xl shadow-sm border border-slate-200 dark:border-dark-border/50">
                         <div className="flex gap-1 text-primary-400 mb-4">
                             <Star size={18} fill="currentColor" /><Star size={18} fill="currentColor" /><Star size={18} fill="currentColor" /><Star size={18} fill="currentColor" /><Star size={18} fill="currentColor" />
                         </div>
                         <p className="text-slate-700 dark:text-slate-300 mb-6 italic">"Antes tardaba horas dibujando zapatas y encuentros complejos. Ahora descargo, adapto y entrego. Ha cambiado mi flujo de trabajo."</p>
                         <div className="flex items-center gap-4">
                             <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?q=80&w=100&auto=format&fit=crop" className="w-12 h-12 rounded-full object-cover" />
                             <div>
                                 <h4 className="font-bold text-slate-900 dark:text-white text-sm">Arq. Laura Méndez</h4>
                                 <p className="text-xs text-slate-500">Estudio L&M</p>
                             </div>
                         </div>
                     </div>
                     <div className="bg-white dark:bg-dark-card p-8 rounded-2xl shadow-sm border border-slate-200 dark:border-dark-border/50">
                         <div className="flex gap-1 text-primary-400 mb-4">
                             <Star size={18} fill="currentColor" /><Star size={18} fill="currentColor" /><Star size={18} fill="currentColor" /><Star size={18} fill="currentColor" /><Star size={18} fill="currentColor" />
                         </div>
                         <p className="text-slate-700 dark:text-slate-300 mb-6 italic">"La calidad técnica es superior a cualquier bloque CAD gratuito que encuentres online. Vale cada centavo de la suscripción."</p>
                         <div className="flex items-center gap-4">
                             <img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=100&auto=format&fit=crop" className="w-12 h-12 rounded-full object-cover" />
                             <div>
                                 <h4 className="font-bold text-slate-900 dark:text-white text-sm">Ing. Carlos Ruiz</h4>
                                 <p className="text-xs text-slate-500">Director de Obra</p>
                             </div>
                         </div>
                     </div>
                 </div>
             </div>
        </div>

        {/* PRICING SECTION - THE CONVERSION POINT */}
        <div id="pricing-section" className="max-w-7xl mx-auto px-6 pb-24">
            <div className="text-center mb-12">
                <h2 className="text-4xl font-bold text-slate-900 dark:text-white mb-4">Planes Simples y Transparentes</h2>
                <p className="text-lg text-slate-500 dark:text-slate-400">Invierte en tu carrera profesional hoy.</p>
            </div>

            {/* Período y moneda */}
            <div className="flex flex-col sm:flex-row items-center justify-center gap-4 mb-12">
                <div className="inline-flex p-1 rounded-xl bg-slate-100 dark:bg-dark-bg">
                    {PERIOD_OPTIONS.map((o) => {
                        const active = periodId === o.id;
                        const discount = discountOf(o.id);
                        return (
                            <button
                                key={o.id}
                                type="button"
                                aria-pressed={active}
                                onClick={() => setPeriodId(o.id)}
                                className={`px-5 py-2 rounded-lg text-sm font-bold transition-all ${
                                    active
                                        ? 'bg-white dark:bg-dark-surface text-slate-900 dark:text-white shadow-sm'
                                        : 'text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white'
                                }`}
                            >
                                {o.label}
                                {discount > 0 && <span className="ml-1.5 text-xs text-green-500">-{discount}%</span>}
                            </button>
                        );
                    })}
                </div>
                <div className="inline-flex p-1 rounded-xl bg-slate-100 dark:bg-dark-bg">
                    {([['ARS', 'Pesos'], ['USD', 'Dólares']] as const).map(([id, label]) => (
                        <button
                            key={id}
                            type="button"
                            aria-pressed={currency === id}
                            onClick={() => setCurrency(id)}
                            className={`px-5 py-2 rounded-lg text-sm font-bold transition-all ${
                                currency === id
                                    ? 'bg-white dark:bg-dark-surface text-slate-900 dark:text-white shadow-sm'
                                    : 'text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-white'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <p className="text-center text-sm text-slate-500 dark:text-slate-400 -mt-6 mb-10">
                {currency === 'ARS' ? 'Pagás en pesos con Mercado Pago.' : 'Pagás en dólares con PayPal.'} El plan se paga por período y no se renueva solo.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-8 items-start">
                
                {plans.map((plan) => {
                    const offer = purchase?.plans.find((p) => p.slug === plan.slug);
                    const isFree = plan.price === 0;
                    // Sin sesión se marca Free, como antes; con sesión, el plan real del usuario
                    const isCurrent = currentUser ? currentUser.planSlug === plan.slug : isFree;
                    const state = offer?.state ?? 'available';
                    const until = offer?.ends_at ? formatDate(offer.ends_at) : null;
                    const quote = offer?.periods.find((p) => p.id === periodId);
                    const amount = quote ? (currency === 'ARS' ? quote.amount_ars : quote.amount_usd) : 0;
                    const blocked = isFree || ['blocked_same', 'blocked_lower', 'blocked_scheduled', 'scheduled'].includes(state) || (isCurrent && state !== 'renew');
                    const renewFrom = offer?.renew_from ? formatDate(offer.renew_from) : null;
                    const startsOn = offer?.starts_at ? formatDate(offer.starts_at) : null;
                    const label = payingPlan === plan.id
                        ? 'Redirigiendo al pago...'
                        : isFree
                            ? (isCurrent ? 'Plan Actual' : 'Plan gratuito')
                            : state === 'blocked_lower' && renewFrom
                                ? `Podés cambiarte desde el ${renewFrom}`
                                : state === 'scheduled' && startsOn
                                    ? `Empieza el ${startsOn}`
                                    : isCurrent && state !== 'renew'
                                        ? 'Plan Actual'
                                        : state === 'renew'
                                            ? 'Renovar'
                                            : state === 'upgrade'
                                                ? `Subir a ${plan.name} · ${money(amount)}`
                                                : state === 'downgrade'
                                                    ? `Cambiar a ${plan.name}`
                                                    : state === 'blocked_scheduled'
                                                        ? 'Cambio programado'
                                                        : 'Comenzar Ahora';
                    const note = isFree
                        ? null
                        : state === 'blocked_same' && until
                            ? `Tu plan está activo hasta ${until}`
                            : state === 'renew' && until
                                ? `Tu plan vence el ${until}. Lo que renueves se suma a esa fecha.`
                                : state === 'upgrade' && until
                                    ? `Pagás la diferencia entre planes. ${plan.name} dura hasta ${until}.`
                                    : state === 'downgrade' && until
                                        ? `Se paga completo y empieza cuando vence tu plan actual, el ${until}. Hasta entonces seguís con tu plan.`
                                        : state === 'blocked_scheduled' || state === 'scheduled'
                                            ? (offer?.message ?? null)
                                            : null;

                    return (
                    <div 
                        key={plan.id}
                        className={`p-8 rounded-3xl border shadow-sm hover:shadow-xl transition-all relative ${
                            plan.isRecommended 
                                ? 'bg-white text-slate-900 border-2 border-primary-400 scale-105 z-10 shadow-2xl' 
                                : 'bg-slate-900 text-white border-slate-700'
                        }`}
                    >
                        {plan.isRecommended && (
                            <div className="absolute top-0 right-0 bg-primary-400 text-slate-900 text-xs font-bold px-3 py-1 rounded-bl-xl rounded-tr-2xl">
                                RECOMENDADO
                            </div>
                        )}

                        <div className="flex items-center gap-2 mb-4">
                            <div className={`p-2 rounded-lg ${
                                plan.isRecommended ? 'bg-slate-100' : 'bg-slate-800'
                            }`}>
                                {plan.price === 0 ? <Zap size={24} className={plan.isRecommended ? 'text-slate-700' : 'text-white'} /> :
                                 plan.price > 20 ? <Crown size={24} className={plan.isRecommended ? 'text-amber-500' : 'text-yellow-400'} /> :
                                 <GraduationCap size={24} className={plan.isRecommended ? 'text-primary-700' : 'text-primary-400'} />
                                }
                            </div>
                            <span className={`font-bold ${plan.isRecommended ? 'text-slate-900' : 'text-white'}`}>{plan.name}</span>
                        </div>

                        {renderPrice(plan)}

                        <p className={`text-sm mb-8 h-10 ${plan.isRecommended ? 'text-slate-600' : 'text-slate-300'}`}>{plan.description}</p>

                        <ul className="space-y-4 mb-8">
                            {plan.features.map((feature, idx) => (
                                <li key={idx} className={`flex items-center gap-3 text-sm ${
                                    plan.isRecommended ? 'text-slate-800' : 'text-slate-200'
                                }`}>
                                    <CheckCircle2 size={18} className={`shrink-0 ${plan.isRecommended ? 'text-green-600' : 'text-primary-400'}`} /> 
                                    {feature}
                                </li>
                            ))}
                        </ul>

                        <button 
                            onClick={() => handleSubscribe(plan)}
                            disabled={payingPlan !== null || blocked}
                            className={`w-full py-3 rounded-xl font-bold transition-colors disabled:opacity-60 disabled:cursor-not-allowed ${
                                plan.isRecommended 
                                    ? 'bg-primary-400 hover:bg-primary-500 text-slate-900 shadow-lg shadow-primary-400/20'
                                    : 'bg-white text-slate-900 hover:bg-slate-100'
                            }`}
                        >
                            {label}
                        </button>
                        {note && (
                            <p className={`text-xs text-center mt-3 ${plan.isRecommended ? 'text-slate-600' : 'text-slate-400'}`}>{note}</p>
                        )}
                    </div>
                    );
                })}

            </div>
        </div>

        {/* FAQ - REMOVING FRICTION */}
        <div className="max-w-3xl mx-auto px-6 mb-20">
            <h2 className="text-2xl font-bold text-center dark:text-white mb-8">Preguntas Frecuentes</h2>
            <div className="space-y-4">
                <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border">
                    <h3 className="font-bold text-slate-900 dark:text-white flex items-center gap-2 text-sm"><HelpCircle size={16} /> ¿Puedo cancelar en cualquier momento?</h3>
                    <p className="text-slate-500 dark:text-slate-400 text-sm mt-2">Sí, no hay contratos de permanencia. Puedes cancelar tu suscripción desde tu panel de usuario cuando quieras.</p>
                </div>
                <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border">
                    <h3 className="font-bold text-slate-900 dark:text-white flex items-center gap-2 text-sm"><HelpCircle size={16} /> ¿Son compatibles con AutoCAD antiguo?</h3>
                    <p className="text-slate-500 dark:text-slate-400 text-sm mt-2">Nuestros archivos DWG se guardan en versiones compatibles desde AutoCAD 2013 en adelante para máxima compatibilidad.</p>
                </div>
            </div>
        </div>

    </div>
  );
};

export default SubscriptionPage;
