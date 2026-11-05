import React, { useState, useMemo, useEffect, useRef } from "react";
import { ClientUser, ConstructionDetail } from "../types";
import SecurityTab from "./SecurityTab";
import CollaboratorStudio from "./CollaboratorStudio";
import FormMessage from "./FormMessage";
import { matchesSearch } from "./explorerFilters";
import { errorText, SubscriptionApi, type ProfileImageKind } from "../services/api";
import { DEFAULT_AVATAR } from "../constants";
import {
  Camera,
  ImagePlus,
  ShieldCheck,
  Upload,
  User,
  CreditCard,
  Bookmark,
  Edit2,
  LogOut,
  Trash2,
  Search,
  ArrowRight,
  ExternalLink,
  Sparkles,
  Filter,
  Layers,
  CheckCircle2,
  Lock,
} from "lucide-react";

interface UserDashboardProps {
  user: ClientUser;
  savedDetails: ConstructionDetail[];
  onLogout: () => void;
  onUpdateUser: (userData: {
    name: string;
    profession?: string;
    bio?: string;
    birthDate?: string;
  }) => Promise<void>;
  onProfileImage?: (kind: ProfileImageKind, file: File | null) => Promise<void>;
  onSelectDetail: (detail: ConstructionDetail) => void;
  onToggleFavorite?: (detailId: string) => void;
  initialTab?: DashboardTab;
  onExploreLibrary?: () => void;
  onOpenPublished?: (slug: string) => void;
  onRefreshUser?: () => Promise<void> | void;
  onGoToPlans?: () => void;
  onGoToRefund?: () => void;
}

type DashboardTab =
  | "profile"
  | "subscription"
  | "saved"
  | "security"
  | "studio";

const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

const UserDashboard: React.FC<UserDashboardProps> = ({
  user,
  savedDetails,
  onLogout,
  onUpdateUser,
  onProfileImage,
  onSelectDetail,
  onToggleFavorite,
  initialTab = "saved",
  onExploreLibrary,
  onOpenPublished,
  onRefreshUser,
  onGoToPlans,
  onGoToRefund,
}) => {
  const [cancelling, setCancelling] = useState(false);
  const [subscriptionMessage, setSubscriptionMessage] = useState<{ type: "error" | "success"; text: string } | null>(null);
  const endsOn = user.subscriptionEndsAt
    ? new Date(user.subscriptionEndsAt).toLocaleDateString("es-AR", { day: "numeric", month: "long", year: "numeric" })
    : null;

  // Cancelar no quita días ya pagados: el plan sigue hasta su vencimiento y no hay reembolso
  const handleCancelSubscription = async () => {
    const until = endsOn ? ` hasta el ${endsOn}` : "";
    if (!window.confirm(`¿Cancelar tu suscripción? Mantenés tu plan${until}, después vuelve a Free y no se renueva. No hay reembolsos.`)) return;
    setCancelling(true);
    setSubscriptionMessage(null);
    try {
      await SubscriptionApi.cancel();
      await onRefreshUser?.();
    } catch (err) {
      setSubscriptionMessage({ type: "error", text: errorText(err, "No se pudo cancelar. Probá de nuevo.") });
    } finally {
      setCancelling(false);
    }
  };
  // Los colaboradores cargan detalles desde "Mis detalles" (el admin usa su panel)
  const canPublish = !!user.roles?.includes("colaborador");
  const [activeTab, setActiveTab] = useState<DashboardTab>(initialTab);
  const [isEditing, setIsEditing] = useState(false);
  const [formData, setFormData] = useState({ ...user });
  const [savedSearchQuery, setSavedSearchQuery] = useState("");
  const [selectedSavedCategory, setSelectedSavedCategory] =
    useState<string>("all");
  const [isSaving, setIsSaving] = useState(false);
  const [profileMessage, setProfileMessage] = useState<{
    type: "error" | "success";
    text: string;
  } | null>(null);
  const [uploading, setUploading] = useState<ProfileImageKind | null>(null);
  const [imageError, setImageError] = useState<string | null>(null);
  const avatarInput = useRef<HTMLInputElement>(null);
  const coverInput = useRef<HTMLInputElement>(null);

  // Si el usuario cambia desde afuera (por ejemplo, al subir la foto), el formulario lo acompaña
  useEffect(() => {
    if (!isEditing) setFormData({ ...user });
  }, [user, isEditing]);

  const handleSaveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    setProfileMessage(null);
    try {
      await onUpdateUser({
        name: formData.name,
        profession: formData.profession,
        bio: formData.bio,
        birthDate: formData.birthDate,
      });
      setIsEditing(false);
      setProfileMessage({ type: "success", text: "Perfil actualizado." });
    } catch (err) {
      setProfileMessage({
        type: "error",
        text: errorText(err, "No se pudo guardar el perfil. Probá de nuevo."),
      });
    } finally {
      setIsSaving(false);
    }
  };

  // Subir (con archivo) o quitar (sin archivo) la foto o la portada
  const handleImage = async (kind: ProfileImageKind, file: File | null) => {
    if (!onProfileImage) return;
    setImageError(null);
    if (file && file.size > MAX_IMAGE_BYTES) {
      setImageError("La imagen puede pesar hasta 2 MB.");
      return;
    }
    setUploading(kind);
    try {
      await onProfileImage(kind, file);
    } catch (err) {
      setImageError(errorText(err, "No se pudo actualizar la imagen. Probá de nuevo."));
    } finally {
      setUploading(null);
    }
  };

  const onFileChosen =
    (kind: ProfileImageKind) => (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      e.target.value = ""; // permite volver a elegir el mismo archivo
      if (file) handleImage(kind, file);
    };

  const hasOwnAvatar = !!user.avatarUrl && user.avatarUrl !== DEFAULT_AVATAR;

  // Categories available in saved items
  const savedCategories = useMemo(() => {
    const cats = new Set<string>();
    savedDetails.forEach((d) => {
      if (d.category) cats.add(d.category);
    });
    return Array.from(cats);
  }, [savedDetails]);

  // Filtered saved details
  const filteredSavedDetails = useMemo(() => {
    // Mismo buscador que el explorador: sin tildes, en título, descripción, etiquetas y autor
    return savedDetails.filter((detail) => {
      const matchesCategory =
        selectedSavedCategory === "all" ||
        detail.category === selectedSavedCategory;
      return matchesCategory && matchesSearch(detail, savedSearchQuery);
    });
  }, [savedDetails, savedSearchQuery, selectedSavedCategory]);

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 py-8 md:py-10 animate-in fade-in duration-500">
      <div className="flex flex-col lg:flex-row gap-8">
        {/* Sidebar Navigation */}
        <div className="w-full lg:w-72 shrink-0">
          <div className="bg-white dark:bg-dark-surface p-6 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm mb-6 text-center relative overflow-hidden">
            <div className="absolute top-0 left-0 right-0 h-16 bg-gradient-to-r from-primary-400/20 via-primary-500/10 to-transparent">
              {user.coverUrl && (
                <img src={user.coverUrl} alt="" className="w-full h-full object-cover" />
              )}
            </div>
            <div className="relative pt-2">
              <img
                src={user.avatarUrl}
                alt={user.name}
                className="w-24 h-24 rounded-full mx-auto mb-4 border-4 border-white dark:border-dark-surface shadow-md object-cover"
              />
              <h2 className="text-xl font-bold text-slate-900 dark:text-white leading-tight">
                {user.name}
              </h2>
              <p className="text-sm text-primary-600 dark:text-primary-400 font-medium mb-2">
                {user.profession || "Profesional de la Construcción"}
              </p>

              <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary-100 dark:bg-primary-950/60 border border-primary-300 dark:border-primary-800 text-primary-900 dark:text-primary-300 text-xs font-bold uppercase tracking-wider">
                <Sparkles
                  size={12}
                  className="text-primary-600 dark:text-primary-400"
                />
                <span>Plan {user.plan}</span>
              </div>

              {/* Quick Counter */}
              <div className="mt-5 pt-4 border-t border-slate-100 dark:border-dark-border/60 grid grid-cols-2 gap-2 text-center">
                <div className="bg-slate-50 dark:bg-dark-bg/60 p-2.5 rounded-2xl">
                  <span className="block text-lg font-bold text-slate-900 dark:text-white">
                    {savedDetails.length}
                  </span>
                  <span className="text-[11px] text-slate-500 uppercase font-semibold">
                    Favoritos
                  </span>
                </div>
                <div className="bg-slate-50 dark:bg-dark-bg/60 p-2.5 rounded-2xl">
                  <span className="block text-lg font-bold text-green-600 dark:text-green-400">
                    Activo
                  </span>
                  <span className="text-[11px] text-slate-500 uppercase font-semibold">
                    Estado
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div className="space-y-2 bg-white dark:bg-dark-surface p-3 rounded-2xl border border-slate-200 dark:border-dark-border shadow-sm">
            <button
              onClick={() => setActiveTab("saved")}
              className={`w-full flex items-center justify-between px-4 py-3 rounded-xl font-bold text-sm transition-all ${
                activeTab === "saved"
                  ? "bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 shadow-md"
                  : "text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-dark-bg"
              }`}
            >
              <div className="flex items-center gap-3">
                <Bookmark
                  size={18}
                  className={activeTab === "saved" ? "fill-current" : ""}
                />
                <span>Detalles Favoritos</span>
              </div>
              <span
                className={`text-xs px-2.5 py-0.5 rounded-full font-bold transition-colors ${
                  activeTab === "saved"
                    ? "bg-primary-400 text-slate-900 dark:bg-slate-900 dark:text-primary-400"
                    : "bg-slate-100 dark:bg-dark-bg text-slate-600 dark:text-slate-400"
                }`}
              >
                {savedDetails.length}
              </span>
            </button>

            {canPublish && (
              <button
                onClick={() => setActiveTab("studio")}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm transition-all ${
                  activeTab === "studio"
                    ? "bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 shadow-md"
                    : "text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-dark-bg"
                }`}
              >
                <Upload size={18} />
                <span>Mis detalles</span>
              </button>
            )}

            <button
              onClick={() => setActiveTab("subscription")}
              className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm transition-all ${
                activeTab === "subscription"
                  ? "bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 shadow-md"
                  : "text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-dark-bg"
              }`}
            >
              <CreditCard size={18} />
              <span>Mi Suscripción</span>
            </button>

            <button
              onClick={() => setActiveTab("profile")}
              className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm transition-all ${
                activeTab === "profile"
                  ? "bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 shadow-md"
                  : "text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-dark-bg"
              }`}
            >
              <User size={18} />
              <span>Editar Perfil</span>
            </button>

            <button
              onClick={() => setActiveTab("security")}
              className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm transition-all ${
                activeTab === "security"
                  ? "bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 shadow-md"
                  : "text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-dark-bg"
              }`}
            >
              <ShieldCheck size={18} />
              <span>Seguridad</span>
            </button>

            <div className="pt-2 border-t border-slate-100 dark:border-dark-border/60 mt-2">
              <button
                onClick={onLogout}
                className="w-full flex items-center gap-3 px-4 py-3 rounded-xl font-bold text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-colors"
              >
                <LogOut size={18} />
                <span>Cerrar Sesión</span>
              </button>
            </div>
          </div>
        </div>

        {/* Content Area */}
        <div className="flex-1">
          {/* TAB: SAVED FAVORITES */}
          {activeTab === "saved" && (
            <div className="space-y-6">
              {/* Header Box */}
              <div className="bg-white dark:bg-dark-surface p-6 md:p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm">
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                  <div>
                    <div className="flex items-center gap-2 mb-1">
                      <span className="p-2 rounded-xl bg-amber-100 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                        <Bookmark size={20} className="fill-current" />
                      </span>
                      <h2 className="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white">
                        Mis Detalles Favoritos
                      </h2>
                    </div>
                    <p className="text-sm text-slate-500 dark:text-slate-400">
                      Colección personalizada de detalles técnicos constructivos
                      guardados para acceso rápido.
                    </p>
                  </div>

                  <div className="flex items-center gap-3">
                    <span className="bg-primary-50 dark:bg-primary-950/40 text-primary-700 dark:text-primary-300 border border-primary-200 dark:border-primary-800/60 px-4 py-2 rounded-2xl text-sm font-bold shadow-sm">
                      {savedDetails.length}{" "}
                      {savedDetails.length === 1
                        ? "detalle guardado"
                        : "detalles guardados"}
                    </span>
                    {onExploreLibrary && (
                      <button
                        onClick={onExploreLibrary}
                        className="bg-primary-400 hover:bg-primary-500 text-slate-900 px-4 py-2 rounded-2xl text-sm font-bold flex items-center gap-2 transition-all shadow-sm"
                      >
                        <span>Explorar Más</span>
                        <ArrowRight size={16} />
                      </button>
                    )}
                  </div>
                </div>

                {/* Filter and Search Bar if has saved details */}
                {savedDetails.length > 0 && (
                  <div className="flex flex-col sm:flex-row gap-3 pt-4 border-t border-slate-100 dark:border-dark-border/60">
                    <div className="relative flex-1">
                      <Search
                        size={16}
                        className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"
                      />
                      <input
                        type="text"
                        placeholder="Buscar por título, material o especificación..."
                        value={savedSearchQuery}
                        onChange={(e) => setSavedSearchQuery(e.target.value)}
                        className="w-full bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl pl-10 pr-4 py-2.5 text-sm text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-primary-400/50"
                      />
                    </div>

                    {/* Category Filter */}
                    {savedCategories.length > 1 && (
                      <div className="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0">
                        <button
                          onClick={() => setSelectedSavedCategory("all")}
                          className={`px-3 py-2 rounded-xl text-xs font-bold whitespace-nowrap transition-all border ${
                            selectedSavedCategory === "all"
                              ? "bg-slate-900 text-white dark:bg-white dark:text-slate-900 border-transparent shadow-sm"
                              : "bg-slate-50 dark:bg-dark-bg text-slate-600 dark:text-slate-400 border-slate-200 dark:border-dark-border hover:bg-slate-100"
                          }`}
                        >
                          Todas ({savedDetails.length})
                        </button>
                        {savedCategories.map((cat) => {
                          const count = savedDetails.filter(
                            (d) => d.category === cat,
                          ).length;
                          return (
                            <button
                              key={cat}
                              onClick={() => setSelectedSavedCategory(cat)}
                              className={`px-3 py-2 rounded-xl text-xs font-bold whitespace-nowrap transition-all border ${
                                selectedSavedCategory === cat
                                  ? "bg-slate-900 text-white dark:bg-white dark:text-slate-900 border-transparent shadow-sm"
                                  : "bg-slate-50 dark:bg-dark-bg text-slate-600 dark:text-slate-400 border-slate-200 dark:border-dark-border hover:bg-slate-100"
                              }`}
                            >
                              {cat} ({count})
                            </button>
                          );
                        })}
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* Grid of Saved Items */}
              {savedDetails.length > 0 ? (
                filteredSavedDetails.length > 0 ? (
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    {filteredSavedDetails.map((detail) => (
                      <div
                        key={detail.id}
                        className="bg-white dark:bg-dark-surface rounded-3xl border border-slate-200 dark:border-dark-border overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col group relative"
                      >
                        {/* Image & Quick Action */}
                        <div
                          className="aspect-[4/3] bg-slate-900 relative overflow-hidden cursor-pointer"
                          onClick={() => onSelectDetail(detail)}
                        >
                          <img
                            src={detail.imageUrl}
                            alt={detail.title}
                            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90 group-hover:opacity-100"
                          />
                          <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-black/20" />

                          {/* Top Badges */}
                          <div className="absolute top-3 left-3 right-3 flex items-center justify-between z-10">
                            <span className="bg-black/60 backdrop-blur-md text-white text-[10px] font-bold px-2.5 py-1 rounded-full border border-white/20 uppercase tracking-wider">
                              {detail.category}
                            </span>

                            {/* Remove favorite button */}
                            <button
                              onClick={(e) => {
                                e.stopPropagation();
                                if (onToggleFavorite)
                                  onToggleFavorite(detail.id);
                              }}
                              className="p-2 rounded-full bg-red-500/90 hover:bg-red-600 text-white backdrop-blur-sm transition-all hover:scale-110 shadow-lg"
                              title="Quitar de favoritos"
                            >
                              <Trash2 size={14} />
                            </button>
                          </div>

                          {/* Access Level Tag */}
                          <div className="absolute bottom-3 left-3 right-3 flex items-center justify-between text-white text-xs">
                            <span className="flex items-center gap-1 bg-white/20 backdrop-blur-md px-2 py-0.5 rounded-md font-medium text-[11px]">
                              <Layers size={12} />{" "}
                              {detail.drawings?.length || 1} planos
                            </span>
                            {detail.requiredPlanName ? (
                              <span className="bg-amber-400 text-slate-900 font-bold px-2 py-0.5 rounded-md text-[10px] flex items-center gap-1">
                                <Lock size={10} /> {detail.requiredPlanName.toUpperCase()}
                              </span>
                            ) : (
                              <span className="bg-emerald-500/80 backdrop-blur-md text-white font-bold px-2 py-0.5 rounded-md text-[10px]">
                                GRATIS
                              </span>
                            )}
                          </div>
                        </div>

                        {/* Content */}
                        <div className="p-5 flex-1 flex flex-col justify-between">
                          <div>
                            <div className="flex items-center gap-2 mb-2">
                              <img
                                src={detail.author.avatarUrl}
                                alt={detail.author.name}
                                className="w-5 h-5 rounded-full object-cover"
                              />
                              <span className="text-xs text-slate-500 dark:text-slate-400 truncate">
                                {detail.author.name}
                              </span>
                            </div>

                            <h3
                              onClick={() => onSelectDetail(detail)}
                              className="font-bold text-slate-900 dark:text-white group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors line-clamp-2 cursor-pointer mb-2 text-base"
                            >
                              {detail.title}
                            </h3>

                            <p className="text-xs text-slate-500 dark:text-slate-400 line-clamp-2 mb-4">
                              {detail.description}
                            </p>
                          </div>

                          <div className="pt-3 border-t border-slate-100 dark:border-dark-border/60 flex items-center justify-between">
                            <span className="text-xs font-semibold text-slate-400">
                              {detail.specs?.length || 0} especificaciones
                            </span>
                            <button
                              onClick={() => onSelectDetail(detail)}
                              className="text-xs font-bold text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1 group-hover:gap-2 transition-all"
                            >
                              <span>Ver detalle</span>
                              <ArrowRight size={14} />
                            </button>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  /* No filter matches */
                  <div className="bg-white dark:bg-dark-surface p-12 rounded-3xl text-center border border-slate-200 dark:border-dark-border">
                    <Filter
                      size={36}
                      className="mx-auto text-slate-300 dark:text-slate-600 mb-3"
                    />
                    <h3 className="text-base font-bold text-slate-700 dark:text-slate-200">
                      No se encontraron detalles con los filtros aplicados
                    </h3>
                    <p className="text-slate-400 text-sm mt-1 mb-4">
                      Prueba con otra palabra clave o selecciona otra categoría.
                    </p>
                    <button
                      onClick={() => {
                        setSavedSearchQuery("");
                        setSelectedSavedCategory("all");
                      }}
                      className="px-4 py-2 bg-slate-100 dark:bg-dark-bg text-slate-700 dark:text-slate-300 rounded-xl text-sm font-bold hover:bg-slate-200"
                    >
                      Limpiar Filtros
                    </button>
                  </div>
                )
              ) : (
                /* Empty state: No favorites at all */
                <div className="bg-white dark:bg-dark-surface p-12 md:p-16 rounded-3xl text-center border-2 border-dashed border-slate-200 dark:border-dark-border/80 shadow-sm">
                  <div className="w-20 h-20 bg-amber-50 dark:bg-amber-950/30 text-amber-500 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner">
                    <Bookmark size={40} className="fill-current" />
                  </div>
                  <h3 className="text-xl md:text-2xl font-bold text-slate-900 dark:text-white mb-2">
                    Aún no tienes detalles en favoritos
                  </h3>
                  <p className="text-slate-500 dark:text-slate-400 text-sm max-w-md mx-auto mb-8 leading-relaxed">
                    Guarda los detalles constructivos que más utilizas para
                    tenerlos siempre organizados y listos durante el diseño o la
                    dirección de obra.
                  </p>
                  {onExploreLibrary && (
                    <button
                      onClick={onExploreLibrary}
                      className="inline-flex items-center gap-2.5 bg-primary-400 hover:bg-primary-500 text-slate-900 px-6 py-3.5 rounded-2xl font-bold text-sm shadow-lg shadow-primary-400/20 hover:scale-105 transition-all"
                    >
                      <Sparkles size={18} />
                      <span>Explorar Biblioteca de Detalles</span>
                      <ArrowRight size={16} />
                    </button>
                  )}
                </div>
              )}
            </div>
          )}

          {/* TAB: SUBSCRIPTION */}
          {activeTab === "subscription" && (
            <div className="bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm">
              <h2 className="text-2xl font-bold dark:text-white mb-6">
                Estado de Suscripción
              </h2>
              <div className="bg-slate-50 dark:bg-dark-bg p-6 rounded-2xl border border-slate-200 dark:border-dark-border mb-6 flex justify-between items-center">
                <div>
                  <p className="text-sm text-slate-500 dark:text-slate-400 mb-1">
                    Plan Actual
                  </p>
                  <h3 className="text-3xl font-bold text-primary-600 dark:text-primary-400">
                    {user.plan}
                  </h3>
                </div>
                <div className="text-right">
                  <p className="text-sm text-slate-500 dark:text-slate-400 mb-1">
                    Estado
                  </p>
                  {user.subscriptionCancelled ? (
                    <span className="bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 px-3 py-1 rounded-full text-xs font-bold uppercase">
                      Cancelada
                    </span>
                  ) : (
                    <span className="bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 px-3 py-1 rounded-full text-xs font-bold uppercase">
                      Activo
                    </span>
                  )}
                </div>
              </div>
              {user.scheduledPlan && (
                <p className="mb-6 text-sm text-slate-700 dark:text-slate-200 bg-slate-100 dark:bg-dark-bg p-4 rounded-2xl">
                  Pasás a {user.scheduledPlan.name} el{" "}
                  {new Date(user.scheduledPlan.startsAt).toLocaleDateString("es-AR", { day: "numeric", month: "long", year: "numeric" })}.
                </p>
              )}
              {user.subscriptionCancelled && (
                <p className="mb-6 text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 p-4 rounded-2xl">
                  Cancelada: tu plan sigue activo hasta {endsOn ?? "su vencimiento"}.
                </p>
              )}
              <div className="space-y-4 mb-8">
                <div className="flex justify-between border-b border-slate-100 dark:border-dark-border pb-3">
                  <span className="text-slate-600 dark:text-slate-300">
                    Fecha de Inicio
                  </span>
                  <span className="font-bold dark:text-white">
                    {user.subscriptionStart || "N/A"}
                  </span>
                </div>
                <div className="flex justify-between border-b border-slate-100 dark:border-dark-border pb-3">
                  <span className="text-slate-600 dark:text-slate-300">
                    Vence el
                  </span>
                  <span className="font-bold dark:text-white">
                    {user.subscriptionEnd
                      ? `${user.subscriptionEnd}${user.daysLeft != null ? ` (${user.daysLeft} días)` : ""}`
                      : "N/A"}
                  </span>
                </div>
                <div className="flex justify-between border-b border-slate-100 dark:border-dark-border pb-3">
                  <span className="text-slate-600 dark:text-slate-300">
                    Colección de Favoritos
                  </span>
                  <span className="font-bold text-primary-600 dark:text-primary-400">
                    {savedDetails.length} items guardados
                  </span>
                </div>
                <div className="pb-3">
                  <div className="flex justify-between mb-2">
                    <span className="text-slate-600 dark:text-slate-300">
                      Detalles consultados
                    </span>
                    <span className="font-bold dark:text-white">
                      {user.quotaUsed ?? 0}
                      {user.quotaLimit != null
                        ? ` de ${user.quotaLimit}`
                        : " (sin límite)"}
                    </span>
                  </div>
                  {user.quotaLimit != null && (
                    <div className="h-2 rounded-full bg-slate-100 dark:bg-dark-bg overflow-hidden">
                      <div
                        className="h-full bg-primary-400 transition-all"
                        style={{
                          width: `${Math.min(100, ((user.quotaUsed ?? 0) / user.quotaLimit) * 100)}%`,
                        }}
                      />
                    </div>
                  )}
                </div>
              </div>
              {subscriptionMessage && (
                <div className="mb-4">
                  <FormMessage {...subscriptionMessage} />
                </div>
              )}
              <div className="flex gap-4">
                <button
                  onClick={onGoToPlans}
                  className="bg-primary-400 hover:bg-primary-500 text-slate-900 px-6 py-3 rounded-xl font-bold transition-all shadow-sm"
                >
                  Actualizar Plan
                </button>
                {user.refundEligible && (
                  <button
                    onClick={onGoToRefund}
                    className="bg-slate-100 dark:bg-dark-bg hover:bg-slate-200 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 px-6 py-3 rounded-xl font-bold transition-colors"
                  >
                    Solicitar reembolso
                  </button>
                )}
                {user.subscriptionPaid && !user.subscriptionCancelled && (
                  <button
                    onClick={handleCancelSubscription}
                    disabled={cancelling}
                    className="text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 px-6 py-3 rounded-xl font-bold transition-colors disabled:opacity-50"
                  >
                    {cancelling ? "Cancelando..." : "Cancelar Suscripción"}
                  </button>
                )}
              </div>
            </div>
          )}

          {/* TAB: PROFILE */}
          {activeTab === "profile" && (
            <div className="bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm">
              <div className="flex justify-between items-center mb-6">
                <h2 className="text-2xl font-bold dark:text-white">
                  Perfil Profesional
                </h2>
                {!isEditing && (
                  <button
                    onClick={() => {
                      setIsEditing(true);
                      setProfileMessage(null);
                    }}
                    className="flex items-center gap-2 text-primary-600 dark:text-primary-400 font-bold hover:underline"
                  >
                    <Edit2 size={16} /> Editar Perfil
                  </button>
                )}
              </div>

              {/* Portada y foto de perfil */}
              {onProfileImage && (
                <div className="mb-8">
                  <div className="relative h-36 md:h-44 rounded-2xl overflow-hidden bg-gradient-to-r from-primary-400/20 via-primary-500/10 to-transparent border border-slate-200 dark:border-dark-border">
                    {user.coverUrl && (
                      <img src={user.coverUrl} alt="Portada" className="w-full h-full object-cover" />
                    )}
                    <div className="absolute top-3 right-3 flex gap-2">
                      <button
                        type="button"
                        onClick={() => coverInput.current?.click()}
                        disabled={uploading !== null}
                        className="flex items-center gap-1.5 bg-white/90 dark:bg-dark-surface/90 backdrop-blur px-3 py-1.5 rounded-lg text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-white dark:hover:bg-dark-surface shadow-sm disabled:opacity-50"
                      >
                        <ImagePlus size={14} />
                        {uploading === "cover" ? "Subiendo..." : "Cambiar portada"}
                      </button>
                      {user.coverUrl && (
                        <button
                          type="button"
                          onClick={() => handleImage("cover", null)}
                          disabled={uploading !== null}
                          title="Quitar portada"
                          className="bg-white/90 dark:bg-dark-surface/90 backdrop-blur px-2 py-1.5 rounded-lg text-red-500 hover:bg-white dark:hover:bg-dark-surface shadow-sm disabled:opacity-50"
                        >
                          <Trash2 size={14} />
                        </button>
                      )}
                    </div>
                  </div>
                  <div className="flex items-end gap-4 -mt-12 ml-6 relative">
                    <img
                      src={user.avatarUrl}
                      alt={user.name}
                      className="w-24 h-24 rounded-full border-4 border-white dark:border-dark-surface shadow-md object-cover bg-white"
                    />
                    <div className="flex items-center gap-4 pb-1">
                      <button
                        type="button"
                        onClick={() => avatarInput.current?.click()}
                        disabled={uploading !== null}
                        className="flex items-center gap-1.5 text-primary-600 dark:text-primary-400 text-sm font-bold hover:underline disabled:opacity-50"
                      >
                        <Camera size={16} />
                        {uploading === "avatar" ? "Subiendo..." : "Cambiar foto"}
                      </button>
                      {hasOwnAvatar && (
                        <button
                          type="button"
                          onClick={() => handleImage("avatar", null)}
                          disabled={uploading !== null}
                          className="text-sm font-bold text-slate-400 hover:text-red-500 disabled:opacity-50"
                        >
                          Quitar
                        </button>
                      )}
                    </div>
                  </div>
                  <div className="mt-3 ml-6 space-y-1">
                    {imageError && <FormMessage type="error" text={imageError} />}
                    <p className="text-xs text-slate-400">JPG, PNG o WebP, hasta 2 MB.</p>
                  </div>
                  <input ref={avatarInput} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={onFileChosen("avatar")} />
                  <input ref={coverInput} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={onFileChosen("cover")} />
                </div>
              )}

              <form onSubmit={handleSaveProfile} className="space-y-6">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div>
                    <label className="block text-xs font-bold uppercase text-slate-500 mb-2">
                      Nombre Completo
                    </label>
                    <input
                      disabled={!isEditing}
                      value={formData.name}
                      onChange={(e) =>
                        setFormData({ ...formData, name: e.target.value })
                      }
                      className="w-full bg-slate-50 dark:bg-dark-bg p-3 rounded-xl border border-slate-200 dark:border-dark-border disabled:opacity-70 dark:text-white"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-bold uppercase text-slate-500 mb-2">
                      Profesión o Especialidad
                    </label>
                    <input
                      disabled={!isEditing}
                      value={formData.profession || ""}
                      onChange={(e) =>
                        setFormData({ ...formData, profession: e.target.value })
                      }
                      className="w-full bg-slate-50 dark:bg-dark-bg p-3 rounded-xl border border-slate-200 dark:border-dark-border disabled:opacity-70 dark:text-white"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-bold uppercase text-slate-500 mb-2">
                      Email Profesional
                    </label>
                    <input
                      disabled
                      value={formData.email}
                      className="w-full bg-slate-100 dark:bg-dark-bg/50 p-3 rounded-xl border border-transparent cursor-not-allowed text-slate-500"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-bold uppercase text-slate-500 mb-2">
                      Fecha Nacimiento
                    </label>
                    <input
                      type="date"
                      disabled={!isEditing}
                      value={formData.birthDate || ""}
                      onChange={(e) =>
                        setFormData({ ...formData, birthDate: e.target.value })
                      }
                      className="w-full bg-slate-50 dark:bg-dark-bg p-3 rounded-xl border border-slate-200 dark:border-dark-border disabled:opacity-70 dark:text-white"
                    />
                  </div>
                </div>
                <div>
                  <label className="block text-xs font-bold uppercase text-slate-500 mb-2">
                    Biografía Profesional
                  </label>
                  <textarea
                    rows={4}
                    disabled={!isEditing}
                    value={formData.bio || ""}
                    onChange={(e) =>
                      setFormData({ ...formData, bio: e.target.value })
                    }
                    className="w-full bg-slate-50 dark:bg-dark-bg p-3 rounded-xl border border-slate-200 dark:border-dark-border disabled:opacity-70 resize-none dark:text-white"
                  />
                </div>

                {profileMessage && !isEditing && (
                  <FormMessage {...profileMessage} />
                )}

                {isEditing && (
                  <div className="flex flex-wrap items-center justify-end gap-4 pt-4">
                    {profileMessage && (
                      <div className="mr-auto">
                        <FormMessage {...profileMessage} />
                      </div>
                    )}
                    <button
                      type="button"
                      onClick={() => {
                        setIsEditing(false);
                        setFormData({ ...user });
                        setProfileMessage(null);
                      }}
                      className="px-6 py-3 font-bold text-slate-500 hover:bg-slate-100 rounded-xl"
                    >
                      Cancelar
                    </button>
                    <button
                      type="submit"
                      disabled={isSaving}
                      className="px-6 py-3 bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold rounded-xl shadow-lg disabled:opacity-50"
                    >
                      {isSaving ? "Guardando..." : "Guardar Cambios"}
                    </button>
                  </div>
                )}
              </form>
            </div>
          )}

          {/* TAB: SECURITY */}
          {activeTab === "security" && <SecurityTab />}

          {/* TAB: STUDIO (colaboradores) */}
          {activeTab === "studio" && canPublish && (
            <CollaboratorStudio onOpenPublished={onOpenPublished} />
          )}

        </div>
      </div>
    </div>
  );
};

export default UserDashboard;
