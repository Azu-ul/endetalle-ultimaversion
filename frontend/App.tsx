import React, {
  useState,
  useMemo,
  useEffect,
  useRef,
  startTransition,
} from "react";
import {
  matchPath,
  useLocation,
  useNavigate,
  useSearchParams,
} from "react-router";
import Sidebar from "./components/Sidebar";
import DetailView from "./components/DetailView";
import UserProfile from "./components/UserProfile";
import Footer from "./components/Footer";
import AdminPanel from "./components/AdminPanel";
import SEOHead from "./components/SEOHead";
import Paywall from "./components/Paywall";
import SubscriptionPage from "./components/SubscriptionPage";
import PaymentResultPage from "./components/PaymentResultPage";
import RefundPage from "./components/RefundPage";
import MessagesPanel from "./components/MessagesPanel";
import ContactPage from "./components/ContactPage";
import AuthorsPage from "./components/AuthorsPage";
import LegalPage from "./components/LegalPage";
import AuthPage from "./components/AuthPage";
import UserDashboard from "./components/UserDashboard";
import ExplorerToolbar from "./components/ExplorerToolbar";
import {
  DEFAULT_FILTERS,
  filterDetails,
  getSelectionTitle,
  subKey,
  type ExplorerFilters,
  type SortOption,
} from "./components/explorerFilters";
import {
  AuthApi,
  CheckoutApi,
  errorText,
  SiteApi,
  DetailsApi,
  UsersApi,
  SocialApi,
  AdminApi,
  AccountApi,
  type ProfileImageKind,
  toReport,
  toClientUser,
  toComment,
  toConfig,
  toCategory,
  toPlan,
  toDetail,
  toPublicUser,
  type ApiDetailFull,
} from "./services/api";
import {
  PATHS,
  type StaticView,
  categoryPath,
  detailPath,
  profilePath,
  safeReturnPath,
} from "./paths";

import {
  ConstructionDetail,
  Author,
  ClientUser,
  ErrorReport,
  Comment,
  SiteConfig,
  PricingPlan,
  Tag,
  CategoryDef,
} from "./types";
import { DEFAULT_CONFIG } from "./constants";
import {
  Menu,
  X,
  CheckCircle2,
  ArrowUpRight,
  Lock,
  ShieldAlert,
  Bookmark,
  ChevronRight,
} from "lucide-react";

type ViewState =
  | "HOME"
  | "DETAIL"
  | "PROFILE"
  | "ADMIN"
  | "SUBSCRIPTION"
  | "MESSAGES"
  | "CONTACT"
  | "AUTHORS"
  | "LEGAL_TERMS"
  | "LEGAL_PRIVACY"
  | "AUTH"
  | "RESET_PASSWORD"
  | "PAYMENT_RESULT"
  | "REFUND"
  | "USER_DASHBOARD";

// Mi cuenta: cuántas veces y cada cuánto se revisa un pago de Mercado Pago que todavía no figura
const PAYMENT_CHECKS = 6;
const PAYMENT_CHECK_EVERY_MS = 4000;

function App() {
  const location = useLocation();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const detailMatch = matchPath("/detalle/:slug", location.pathname);
  const profileMatch = matchPath("/perfil/:idSlug", location.pathname);
  const categoryMatch =
    matchPath("/categoria/:cat/:sub", location.pathname) ??
    matchPath("/categoria/:cat", location.pathname);
  const routeSlug = detailMatch?.params.slug;
  const routeAuthorId = profileMatch?.params.idSlug?.match(/^\d+/)?.[0];
  const staticView = (Object.keys(PATHS) as StaticView[]).find(
    (v) => PATHS[v] === location.pathname,
  );
  const isKnownRoute = !!(
    staticView ||
    detailMatch ||
    routeAuthorId ||
    categoryMatch
  );

  const [details, setDetails] = useState<ConstructionDetail[]>([]);
  const [reports, setReports] = useState<ErrorReport[]>([]);
  const [comments, setComments] = useState<Comment[]>([]);
  const [config, setConfig] = useState<SiteConfig>(DEFAULT_CONFIG);
  const [plans, setPlans] = useState<PricingPlan[]>([]);
  const [categories, setCategories] = useState<CategoryDef[]>([]);

  const [selectedDetail, setSelectedDetail] =
    useState<ConstructionDetail | null>(null);
  const [selectedAuthor, setSelectedAuthor] = useState<Author | null>(null);
  const [authorDetails, setAuthorDetails] = useState<
    ConstructionDetail[] | null
  >(null);
  const [searchTerm, setSearchTerm] = useState("");

  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(
    () => localStorage.getItem("sidebarCollapsed") === "1",
  );
  const toggleSidebar = () => {
    setIsSidebarCollapsed((prev) => {
      localStorage.setItem("sidebarCollapsed", prev ? "0" : "1");
      return !prev;
    });
  };
  const [isDarkMode, setIsDarkMode] = useState(false);
  // Detalle sin acceso: se muestra el Paywall sin tocar la pantalla de fondo
  const [paywallDetail, setPaywallDetail] =
    useState<ConstructionDetail | null>(null);
  const [paywallReason, setPaywallReason] = useState<
    "login" | "plan" | "quota" | "verify"
  >("plan");
  const [sortBy, setSortBy] = useState<SortOption>("popular");
  const [filters, setFilters] = useState<ExplorerFilters>(DEFAULT_FILTERS);

  const [currentUser, setCurrentUser] = useState<ClientUser | null>(null);
  const [favoriteIds, setFavoriteIds] = useState<string[]>([]);
  const [favoriteToast, setFavoriteToast] = useState<string | null>(null);

  const [authLoading, setAuthLoading] = useState(true);
  const mainRef = useRef<HTMLElement>(null);
  const isSuperadmin = currentUser?.roles?.includes("superadmin") ?? false;
  // Mensajería interna: solo para el equipo (colaboradores y superadmin)
  const canMessage =
    isSuperadmin || (currentUser?.roles?.includes("colaborador") ?? false);

  // Pantalla actual, según la URL. Detalle y perfil se muestran recién cuando
  // sus datos coinciden con la URL (mientras tanto queda el explorador).
  let view: ViewState = staticView ?? "HOME";
  if (routeSlug !== undefined && selectedDetail?.slug === routeSlug)
    view = "DETAIL";
  if (routeAuthorId && selectedAuthor?.id === routeAuthorId) view = "PROFILE";

  const goTo = (target: StaticView) => navigate(PATHS[target]);

  const handleLogout = () => {
    AuthApi.logout().catch(() => {});
    // Juntos, para que "Mi cuenta" no mande a ingresar al quedar sin sesión
    startTransition(() => {
      setCurrentUser(null);
      goTo("HOME");
    });
  };

  useEffect(() => {
    SiteApi.categories()
      .then((c) => setCategories(c.map(toCategory)))
      .catch(console.error);
    SiteApi.plans()
      .then((p) => setPlans(p.map(toPlan)))
      .catch(console.error);
    SiteApi.settings()
      .then((s) => setConfig(toConfig(s)))
      .catch(console.error);
  }, []);

  // Reportes de error para el panel admin (al entrar al panel, siempre frescos)
  useEffect(() => {
    if (view !== "ADMIN" || !isSuperadmin) return;
    AdminApi.reports()
      .then((list) => setReports(list.map(toReport)))
      .catch(console.error);
  }, [view, isSuperadmin]);

  // Favoritos guardados en la cuenta (sin sesión no hay favoritos)
  useEffect(() => {
    if (!currentUser) {
      setFavoriteIds([]);
      return;
    }
    SocialApi.favorites()
      .then((ids) => setFavoriteIds(ids.map(String)))
      .catch(console.error);
  }, [currentUser?.id]);

  // Al cambiar de pantalla, detalle, autor o categoría, volver al inicio.
  useEffect(() => {
    mainRef.current?.scrollTo({ top: 0, behavior: "instant" });
  }, [view, location.pathname, filters.categories, filters.subcategories]);

  // Rutas que no existen -> inicio. El panel admin, solo para el superadmin.
  useEffect(() => {
    if (!isKnownRoute) navigate("/", { replace: true });
  }, [isKnownRoute]);

  useEffect(() => {
    if (view === "ADMIN" && !authLoading && !isSuperadmin)
      navigate("/", { replace: true });
    if (view === "MESSAGES" && !authLoading && !canMessage)
      navigate("/", { replace: true });
  }, [view, authLoading, isSuperadmin, canMessage]);

  // Al cambiar de URL se cierra el Paywall (por ejemplo, con "atrás")
  useEffect(() => {
    setPaywallDetail(null);
  }, [location.pathname]);

  // Ruta que corresponde a los filtros: una sola categoría (y como mucho
  // una subcategoría) tiene URL propia; cualquier otra combinación es "/".
  const filtersPath = (f: ExplorerFilters) => {
    if (f.categories.length !== 1) return "/";
    const cat = f.categories[0];
    const subs = f.subcategories.filter((k) => k.startsWith(`${cat}::`));
    if (subs.length > 1) return "/";
    const sub = subs[0]?.slice(cat.length + 2);
    return categoryPath(categories, cat, sub) ?? "/";
  };

  // La URL manda sobre la categoría elegida: al entrar por un link y con atrás/adelante
  useEffect(() => {
    if (categories.length === 0) return;
    let wanted: Pick<ExplorerFilters, "categories" | "subcategories">;
    if (location.pathname === "/") {
      wanted = { categories: [], subcategories: [] };
    } else if (categoryMatch) {
      const { cat: catSlug, sub: subSlug } = categoryMatch.params as {
        cat?: string;
        sub?: string;
      };
      const cat = categories.find((c) => c.id === catSlug);
      const subName = Object.entries(cat?.subcategorySlugs ?? {}).find(
        ([, slug]) => slug === subSlug,
      )?.[0];
      if (!cat || (subSlug && !subName)) {
        navigate("/", { replace: true });
        return;
      }
      wanted = {
        categories: [cat.name],
        subcategories: subName ? [subKey(cat.name, subName)] : [],
      };
    } else {
      return;
    }
    setFilters((f) =>
      filtersPath(f) === location.pathname ? f : { ...f, ...wanted },
    );
  }, [location.pathname, categories]);

  // Entrar a /detalle/:slug por link (o volver con atrás/adelante)
  useEffect(() => {
    if (!routeSlug || selectedDetail?.slug === routeSlug) return;
    let cancelled = false;
    DetailsApi.show(routeSlug)
      .then((res) => {
        if (!cancelled && checkDetailAccess(res))
          setSelectedDetail(toDetail(res));
      })
      .catch(() => {
        if (!cancelled) navigate("/", { replace: true });
      });
    return () => {
      cancelled = true;
    };
  }, [routeSlug]);

  // Perfil público: /perfil/:id-nombre
  useEffect(() => {
    if (!routeAuthorId || authLoading) return;
    let cancelled = false;
    UsersApi.show(routeAuthorId)
      .then((res) => {
        if (cancelled) return;
        const author = toPublicUser(res);
        setSelectedAuthor(author);
        setAuthorDetails(res.details.map(toDetail));
        // Si el nombre cambió, se corrige la URL sin romper el link viejo
        const canonical = profilePath(author);
        if (canonical !== location.pathname)
          navigate(canonical, { replace: true });
      })
      .catch(() => {
        if (!cancelled) navigate("/", { replace: true });
      });
    return () => {
      cancelled = true;
    };
  }, [routeAuthorId, authLoading, currentUser?.id]);

  useEffect(() => {
    if (favoriteToast) {
      const timer = setTimeout(() => setFavoriteToast(null), 3000);
      return () => clearTimeout(timer);
    }
  }, [favoriteToast]);

  useEffect(() => {
    AuthApi.me()
      .then((u) => setCurrentUser(toClientUser(u)))
      .catch(() => {})
      .finally(() => setAuthLoading(false));
  }, []);

  // Volvió del enlace del email de verificación (?verificado=1 o 0)
  useEffect(() => {
    const result = searchParams.get("verificado");
    if (result === null || authLoading) return;
    setFavoriteToast(
      result === "1"
        ? "¡Email verificado! Ya podés usar toda la biblioteca."
        : result === "ya"
          ? "Tu cuenta ya estaba verificada."
          : "El enlace de verificación venció o no es válido. Pedí uno nuevo desde el sitio.",
    );
    if (result === "1" && currentUser)
      AuthApi.me().then((u) => setCurrentUser(toClientUser(u))).catch(() => {});
    navigate(location.pathname, { replace: true });
  }, [authLoading]);

  // detailsVersion sube cuando el admin publica algo y hay que recargar el explorador
  const [detailsVersion, setDetailsVersion] = useState(0);
  useEffect(() => {
    if (authLoading) return;
    DetailsApi.list()
      .then((r) => setDetails(r.data.map(toDetail)))
      .catch(console.error);
  }, [authLoading, currentUser?.id, detailsVersion]);

  const handleToggleFavorite = (detailId: string, e?: React.MouseEvent) => {
    if (e) e.stopPropagation();
    // Sin sesión: a ingresar y después de vuelta acá
    if (!currentUser) {
      navigate(`${PATHS.AUTH}?volver=${encodeURIComponent(location.pathname)}`);
      return;
    }
    const isFavorite = !favoriteIds.includes(detailId);
    const add = (ids: string[]) => [detailId, ...ids];
    const remove = (ids: string[]) => ids.filter((id) => id !== detailId);
    // Se marca al instante y se deshace si la API falla
    setFavoriteIds(isFavorite ? add : remove);
    setFavoriteToast(
      isFavorite ? "★ Guardado en tus favoritos" : "Eliminado de favoritos",
    );
    (isFavorite
      ? SocialApi.addFavorite(detailId)
      : SocialApi.removeFavorite(detailId)
    ).catch(() => {
      setFavoriteIds(isFavorite ? remove : add);
      setFavoriteToast("No se pudo guardar el cambio. Probá de nuevo.");
    });
  };

  // Comentarios del detalle abierto
  const loadComments = (detailId: string) =>
    SocialApi.comments(detailId)
      .then((list) => setComments(list.map((c) => toComment(c, detailId))))
      .catch(console.error);

  useEffect(() => {
    setComments([]);
    if (view === "DETAIL" && selectedDetail) loadComments(selectedDetail.id);
  }, [view, selectedDetail?.id]);

  const handleAddComment = async (
    detailId: string,
    text: string,
    parentId?: string,
  ) => {
    await SocialApi.addComment(detailId, text, parentId);
    await loadComments(detailId);
  };

  const handleEditComment = async (commentId: string, text: string) => {
    await SocialApi.editComment(commentId, text);
    if (selectedDetail) await loadComments(selectedDetail.id);
  };

  const handleDeleteComment = async (commentId: string) => {
    await SocialApi.deleteComment(commentId);
    if (selectedDetail) await loadComments(selectedDetail.id);
  };

  const handleReportError = async (detailId: string, description: string) => {
    await SocialApi.report(detailId, description);
  };

  // Voto técnico: actualiza el detalle abierto y el contador del explorador
  const handleToggleVote = async () => {
    if (!selectedDetail) return;
    const res = await SocialApi.vote(
      selectedDetail.id,
      !selectedDetail.hasVoted,
    );
    const update = (d: ConstructionDetail) =>
      d.id === selectedDetail.id
        ? { ...d, likes: res.votes_count, hasVoted: res.voted }
        : d;
    setSelectedDetail((d) => (d ? update(d) : d));
    setDetails((list) => list.map(update));
  };

  useEffect(() => {
    if (isDarkMode) document.body.classList.add("dark");
    else document.body.classList.remove("dark");
  }, [isDarkMode]);

  // Detalles visibles, sin filtrar (el explorador los usa para los contadores)
  const baseDetails = useMemo(
    () =>
      details.filter(
        (detail) => detail.status === "Published" || view === "ADMIN",
      ),
    [details, view],
  );

  const filteredDetails = useMemo(() => {
    // Búsqueda + categorías + etiquetas + resto de filtros
    const list = filterDetails(baseDetails, {
      search: searchTerm,
      filters,
      favoriteIds,
    });

    // Sort
    if (sortBy === "date") {
      list.sort(
        (a, b) =>
          new Date(b.publishedDate || 0).getTime() -
          new Date(a.publishedDate || 0).getTime(),
      );
    } else if (sortBy === "az") {
      list.sort((a, b) =>
        a.title.localeCompare(b.title, "es", { sensitivity: "base" }),
      );
    } else if (sortBy === "za") {
      list.sort((a, b) =>
        b.title.localeCompare(a.title, "es", { sensitivity: "base" }),
      );
    } else {
      list.sort((a, b) => (b.likes || 0) - (a.likes || 0));
    }
    return list;
  }, [baseDetails, sortBy, filters, favoriteIds, searchTerm]);

  // "Me gusta" a un perfil. Sin sesión lleva a ingresar y devuelve null.
  const handleToggleUserLike = async (author: Author) => {
    if (!currentUser) {
      navigate(`${PATHS.AUTH}?volver=${encodeURIComponent(location.pathname)}`);
      return null;
    }
    const res = await UsersApi.like(author.id!, !author.likedByMe);
    setSelectedAuthor((a) =>
      a && a.id === author.id
        ? { ...a, likedByMe: res.liked, likesCount: res.likes_count }
        : a,
    );
    return res;
  };

  // Desde el Sidebar: ir a UNA categoría (o a "all" para ver todo)
  const handleSelectCategory = (cat: string | "all") => {
    setFilters((f) => ({
      ...f,
      categories: cat === "all" ? [] : [cat],
      subcategories: [],
    }));
    if (isMobileMenuOpen) setIsMobileMenuOpen(false);
    navigate(cat === "all" ? "/" : (categoryPath(categories, cat) ?? "/"));
  };

  // Desde el Sidebar: ir a UNA subcategoría
  const handleSelectSubcategory = (cat: string, sub: string) => {
    setFilters((f) => ({
      ...f,
      categories: [cat],
      subcategories: [subKey(cat, sub)],
    }));
    if (isMobileMenuOpen) setIsMobileMenuOpen(false);
    navigate(categoryPath(categories, cat, sub) ?? "/");
  };

  // Desde la barra del explorador: la URL acompaña a los filtros
  const handleFiltersChange = (next: ExplorerFilters) => {
    setFilters(next);
    if (location.pathname !== "/" && !categoryMatch) return;
    const target = filtersPath(next);
    if (target !== location.pathname) navigate(target, { replace: true });
  };

  // Sin acceso abre el Paywall; con acceso actualiza el cupo usado. Devuelve si hubo acceso.
  const checkDetailAccess = (res: ApiDetailFull) => {
    if (!res.access.allowed) {
      setPaywallDetail(toDetail(res));
      setPaywallReason(res.access.reason ?? "plan");
      return false;
    }
    if (currentUser) {
      AuthApi.me()
        .then((u) => setCurrentUser(toClientUser(u)))
        .catch(() => {});
    }
    return true;
  };

  const handleOpenDetail = async (detail: ConstructionDetail) => {
    try {
      const res = await DetailsApi.show(detail.slug!);
      if (!checkDetailAccess(res)) return;
      // Detalle y URL juntos, para que no se vea el explorador entre uno y otro
      startTransition(() => {
        setSelectedDetail(toDetail(res));
        navigate(detailPath(res.slug));
      });
    } catch {
      alert("No se pudo abrir el detalle. Probá de nuevo.");
    }
  };

  const handleOpenProfile = (author: Author) => {
    if (!author.id) return;
    setSelectedAuthor(author);
    setAuthorDetails(null);
    navigate(profilePath(author));
  };

  // "Volver": atrás en el historial si se vino navegando; al inicio si se entró por link
  const handleGoBack = () => {
    if (location.key !== "default") navigate(-1);
    else navigate("/");
  };

  const handleNavigate = (target: StaticView) => {
    goTo(target);
  };

  const handleResolveReport = (reportId: string) => {
    AdminApi.resolveReport(reportId)
      .then((r) => {
        const resolved = toReport(r);
        setReports((list) =>
          list.map((x) => (x.id === resolved.id ? resolved : x)),
        );
      })
      .catch(() => alert("No se pudo marcar el reporte como resuelto."));
  };

  // Crea la orden en el servidor y manda al checkout de Mercado Pago (pesos) o PayPal (dólares)
  const handleSubscribe = async (
    plan: PricingPlan,
    periodId: string,
    currency: "ARS" | "USD",
  ): Promise<boolean> => {
    if (!currentUser) {
      navigate(`${PATHS.AUTH}?volver=${PATHS.SUBSCRIPTION}`);
      return false;
    }
    if (currentUser.emailVerified === false) {
      setFavoriteToast("Verificá tu email para suscribirte: te mandamos un enlace al registrarte.");
      return false;
    }
    try {
      const res = await CheckoutApi.start({
        plan: plan.slug ?? "",
        period: periodId,
        provider: currency === "ARS" ? "mercadopago" : "paypal",
      });
      window.location.href = res.url;
      return true;
    } catch (err) {
      setFavoriteToast(errorText(err, "No se pudo iniciar el pago. Probá de nuevo."));
      return false;
    }
  };

  // Después de ingresar: a donde quería ir (?volver=) o a "Mi cuenta"
  const handleLogin = (user: ClientUser) => {
    setCurrentUser(user);
    if (user.emailVerified === false)
      setFavoriteToast("Revisá tu email: te mandamos un enlace para verificar tu cuenta.");
    navigate(
      safeReturnPath(searchParams.get("volver")) ?? PATHS.USER_DASHBOARD,
      { replace: true },
    );
  };

  // Los errores los muestra el panel; acá solo se actualiza el usuario
  const handleUpdateUser = async (data: {
    name: string;
    profession?: string;
    bio?: string;
    birthDate?: string;
  }) => {
    const u = await AccountApi.updateProfile({
      name: data.name,
      profession: data.profession || null,
      bio: data.bio || null,
      birth_date: data.birthDate || null,
    });
    setCurrentUser(toClientUser(u));
  };

  // Foto de perfil o portada: con archivo se sube, sin archivo se quita
  const handleProfileImage = async (
    kind: ProfileImageKind,
    file: File | null,
  ) => {
    const u = file
      ? await AccountApi.uploadImage(kind, file)
      : await AccountApi.deleteImage(kind);
    setCurrentUser(toClientUser(u));
  };

  // El botón de arrepentimiento necesita sesión: si no hay, pide iniciar sesión y vuelve acá
  useEffect(() => {
    if (view === "REFUND" && !authLoading && !currentUser)
      navigate(`${PATHS.AUTH}?volver=${PATHS.REFUND}`, { replace: true });
  }, [view, authLoading, currentUser]);

  // El resultado de un pago necesita sesión (se vuelve acá después de ingresar)
  useEffect(() => {
    if (view === "PAYMENT_RESULT" && !authLoading && !currentUser)
      navigate(
        `${PATHS.AUTH}?volver=${encodeURIComponent(location.pathname + location.search)}`,
        { replace: true },
      );
  }, [view, authLoading, currentUser]);

  // Al abrir "Mi cuenta" se acreditan los pagos de Mercado Pago que quedaron sin confirmar
  // (el comprador no siempre vuelve al sitio y en local no llegan los webhooks)
  useEffect(() => {
    if (view !== "USER_DASHBOARD" || !currentUser) return;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const check = async (attempt: number) => {
      try {
        const r = await CheckoutApi.sync();
        if (cancelled) return;
        if (r.credited > 0) {
          // La respuesta ya trae el plan y el vencimiento nuevos: se muestran sin volver a abrir la pantalla
          if (r.user) setCurrentUser(toClientUser(r.user));
          else await AuthApi.me().then((u) => setCurrentUser(toClientUser(u)));
          setFavoriteToast("Acreditamos tu pago: tu plan ya está activo.");
          return;
        }
        // Mercado Pago a veces tarda unos segundos: si hay una compra reciente sin confirmar, se vuelve a preguntar
        if (r.pending > 0 && attempt < PAYMENT_CHECKS) {
          timer = setTimeout(() => check(attempt + 1), PAYMENT_CHECK_EVERY_MS);
        }
      } catch {
        // sin conexión: no se muestra nada
      }
    };
    check(1);

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [view, currentUser?.id]);

  // "Mi cuenta" necesita sesión
  useEffect(() => {
    if (view === "USER_DASHBOARD" && !authLoading && !currentUser)
      navigate(`${PATHS.AUTH}?volver=${PATHS.USER_DASHBOARD}`, {
        replace: true,
      });
  }, [view, authLoading, currentUser]);

  // Favoritos completos para "Mi cuenta" (no dependen del listado del explorador)
  const [favoriteDetails, setFavoriteDetails] = useState<ConstructionDetail[]>(
    [],
  );
  useEffect(() => {
    if (view !== "USER_DASHBOARD" || !currentUser) return;
    SocialApi.favoriteDetails()
      .then((list) => setFavoriteDetails(list.map(toDetail)))
      .catch(console.error);
  }, [view, currentUser?.id]);

  // Al quitar un favorito desde el panel desaparece al instante
  const savedDetails = useMemo(
    () => favoriteDetails.filter((d) => favoriteIds.includes(d.id)),
    [favoriteDetails, favoriteIds],
  );

  const seoDetail = view === "DETAIL" ? selectedDetail : null;

  if (config.maintenanceMode && !isSuperadmin) {
    if (view === "AUTH") {
      return <AuthPage onLogin={handleLogin} onClose={() => goTo("HOME")} />;
    }
    return (
      <div className="h-screen w-full flex flex-col items-center justify-center bg-slate-900 text-white text-center p-4">
        <SEOHead title="Mantenimiento | enDetalle" />
        <ShieldAlert size={64} className="text-primary-400 mb-6" />
        <h1 className="text-4xl font-bold mb-4">
          {config.siteTitle} en Mantenimiento
        </h1>
        <button
          onClick={() => goTo("AUTH")}
          className="mt-8 text-sm text-slate-600 hover:text-white underline"
        >
          Acceso Admin
        </button>
      </div>
    );
  }
  return (
    <div
      className={`flex h-screen w-full font-sans overflow-hidden transition-colors duration-300 ${isDarkMode ? "bg-dark-bg text-slate-100" : "bg-slate-50 text-slate-800"}`}
    >
      <SEOHead
        title={
          seoDetail
            ? `${seoDetail.seoTitle || seoDetail.title} | ${config.siteTitle}`
            : view === "PROFILE" && selectedAuthor
              ? `${selectedAuthor.name} | ${config.siteTitle}`
              : config.siteTitle
        }
        description={
          seoDetail?.metaDescription ||
          seoDetail?.description ||
          "Biblioteca de detalles constructivos."
        }
        image={seoDetail?.imageUrl}
      />

      {paywallDetail && (
        <Paywall
          detail={paywallDetail}
          reason={paywallReason}
          config={config}
          onSubscribe={() => {
            setPaywallDetail(null);
            goTo("SUBSCRIPTION");
          }}
          onLogin={() => {
            // Después de ingresar vuelve a este detalle
            setPaywallDetail(null);
            navigate(
              `${PATHS.AUTH}?volver=${encodeURIComponent(detailPath(paywallDetail.slug!))}`,
            );
          }}
          onResendVerification={() => {
            AuthApi.resendVerification()
              .then((r) => setFavoriteToast(r.message))
              .catch((err) => setFavoriteToast(errorText(err, "No se pudo reenviar el email.")));
          }}
          onClose={() => {
            setPaywallDetail(null);
            // Si se entró por el link del detalle, se sale de esa URL
            if (detailMatch) handleGoBack();
          }}
        />
      )}

      {/* Floating Favorite Toast */}
      {favoriteToast && (
        <div className="fixed bottom-6 right-6 z-50 bg-slate-900 text-white px-5 py-3 rounded-2xl shadow-2xl flex items-center gap-3 border border-slate-700 animate-in fade-in slide-in-from-bottom-5">
          <Bookmark
            size={18}
            className="text-amber-400 fill-amber-400 shrink-0"
          />
          <span className="text-sm font-semibold">{favoriteToast}</span>
        </div>
      )}

      <div className="md:hidden fixed top-4 left-4 z-50">
        <button
          onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
          className="bg-white dark:bg-dark-surface p-2.5 rounded-lg shadow-md border border-slate-200 dark:border-dark-border"
        >
          {isMobileMenuOpen ? <X size={20} /> : <Menu size={20} />}
        </button>
      </div>

      {isSidebarCollapsed && (
        <button
          onClick={toggleSidebar}
          title="Mostrar menú"
          aria-label="Mostrar menú"
          className="hidden md:flex fixed left-0 top-1/2 -translate-y-1/2 z-30 w-6 h-12 items-center justify-center rounded-r-lg bg-white dark:bg-dark-surface border border-l-0 border-slate-200 dark:border-dark-border text-slate-400 hover:text-slate-900 dark:hover:text-white shadow-sm transition-colors"
        >
          <ChevronRight size={16} />
        </button>
      )}

      <div
        className={`fixed inset-y-0 left-0 z-40 w-72 shrink-0 transform transition-[transform,width] duration-300 ease-in-out md:relative md:translate-x-0 ${isSidebarCollapsed ? "md:w-0 md:overflow-hidden" : "md:w-72"} ${isMobileMenuOpen ? "translate-x-0 shadow-2xl" : "-translate-x-full"}`}
      >
        <Sidebar
          categories={categories}
          selectedCategories={filters.categories}
          onSelectCategory={handleSelectCategory}
          selectedSubcategories={filters.subcategories}
          onSelectSubcategory={handleSelectSubcategory}
          totalItems={filteredDetails.length}
          isDarkMode={isDarkMode}
          toggleDarkMode={() => setIsDarkMode(!isDarkMode)}
          onOpenAdmin={() => {
            goTo("ADMIN");
            setIsMobileMenuOpen(false);
          }}
          onViewPlans={() => goTo("SUBSCRIPTION")}
          onOpenAuth={() => goTo("AUTH")}
          onCollapse={toggleSidebar}
          currentUser={currentUser}
          isSuperadmin={isSuperadmin}
          authLoading={authLoading}
          onOpenDashboard={() => goTo("USER_DASHBOARD")}
          savedCount={favoriteIds.length}
          onOpenFavorites={() => {
            goTo("USER_DASHBOARD");
            setIsMobileMenuOpen(false);
          }}
          onLogout={() => {
            setIsMobileMenuOpen(false);
            handleLogout();
          }}
          canMessage={canMessage}
          onOpenMessages={() => {
            goTo("MESSAGES");
            setIsMobileMenuOpen(false);
          }}
        />
      </div>

      <main
        ref={mainRef}
        className="flex-1 h-full overflow-y-auto relative scroll-smooth"
      >
        <div className="p-6 md:p-10 min-h-screen pb-0 flex flex-col">
          <div className="flex-1">
            {view === "HOME" && (
              <div className="max-w-[1800px] mx-auto">
                <div className="flex justify-between items-center mb-6">
                  <h2 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    {searchTerm
                      ? `Resultados para "${searchTerm}"`
                      : getSelectionTitle(filters)}
                    <span className="text-xs bg-slate-200 dark:bg-slate-700 px-2 py-1 rounded-full text-slate-500">
                      {filteredDetails.length}
                    </span>
                  </h2>
                </div>
                <ExplorerToolbar
                  details={baseDetails}
                  favoriteIds={favoriteIds}
                  categories={categories}
                  searchTerm={searchTerm}
                  onSearchChange={setSearchTerm}
                  sortBy={sortBy}
                  onSortChange={setSortBy}
                  filters={filters}
                  onFiltersChange={handleFiltersChange}
                  resultCount={filteredDetails.length}
                />
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                  {filteredDetails.map((detail) => (
                    <div
                      key={detail.id}
                      onClick={() => handleOpenDetail(detail)}
                      className="group cursor-pointer relative bg-slate-900 rounded-2xl overflow-hidden shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-300 aspect-[9/16]"
                    >
                      <img
                        src={detail.imageUrl}
                        className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110 opacity-90 group-hover:opacity-100"
                      />
                      <div className="absolute inset-0 bg-gradient-to-t from-black/95 via-black/20 to-black/10 opacity-80 group-hover:opacity-90 transition-opacity" />

                      <div className="absolute top-4 left-4 right-4 flex justify-between items-start z-10">
                        <div className="flex items-center gap-2">
                          <div className="relative">
                            <img
                              src={detail.author.avatarUrl}
                              className="w-8 h-8 rounded-full border border-white/30"
                            />
                            {detail.isVerified && (
                              <div className="absolute -bottom-1 -right-1 bg-primary-400 rounded-full p-0.5">
                                <CheckCircle2
                                  size={8}
                                  className="text-slate-900"
                                />
                              </div>
                            )}
                          </div>
                          <span className="text-xs font-bold text-white/90 drop-shadow-md truncate max-w-[100px]">
                            {detail.author.name}
                          </span>
                        </div>
                        <div className="flex items-center gap-2">
                          <span className="bg-white/10 backdrop-blur-md text-white text-[10px] font-bold px-2 py-1 rounded-md border border-white/10 uppercase tracking-wide">
                            {detail.category.split(" ")[0]}
                          </span>
                          <button
                            onClick={(e) => handleToggleFavorite(detail.id, e)}
                            className={`p-2 rounded-full backdrop-blur-md border transition-all duration-200 hover:scale-110 active:scale-95 shadow-md ${
                              favoriteIds.includes(detail.id)
                                ? "bg-amber-400 text-slate-900 border-amber-400 shadow-amber-400/40"
                                : "bg-black/50 hover:bg-black/80 text-white/90 border-white/20 hover:text-white"
                            }`}
                            title={
                              favoriteIds.includes(detail.id)
                                ? "Quitar de favoritos"
                                : "Guardar como Favorito"
                            }
                          >
                            <Bookmark
                              size={14}
                              fill={
                                favoriteIds.includes(detail.id)
                                  ? "currentColor"
                                  : "none"
                              }
                            />
                          </button>
                        </div>
                      </div>

                      <div className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-20 pointer-events-none">
                        <div className="bg-primary-400 text-slate-900 rounded-full p-4 transform scale-50 group-hover:scale-100 transition-transform duration-300 shadow-2xl">
                          {detail.locked ? (
                            <Lock size={24} />
                          ) : (
                            <ArrowUpRight size={28} strokeWidth={2.5} />
                          )}
                        </div>
                      </div>

                      <div className="absolute bottom-0 left-0 right-0 p-5 z-10">
                        <h3 className="font-bold text-xl text-white mb-2 leading-tight line-clamp-2 group-hover:text-primary-400 transition-colors">
                          {detail.title}
                        </h3>
                        <div className="flex justify-between items-end">
                          <p className="text-xs text-slate-400 line-clamp-1">
                            {detail.description}
                          </p>
                          <div className="flex gap-2">
                            {detail.requiredPlanName && (
                              <span className="bg-slate-800 text-primary-400 text-[10px] font-bold px-2 py-1 rounded border border-primary-400/30 flex items-center gap-1">
                                <Lock size={10} />{" "}
                                {detail.requiredPlanName.toUpperCase()}
                              </span>
                            )}
                          </div>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>

                {filteredDetails.length === 0 && (
                  <div className="text-center py-20 text-slate-500 dark:text-slate-400">
                    <p className="font-bold text-lg">
                      No encontramos detalles con esos filtros
                    </p>
                    <p className="text-sm mt-1">
                      Probá quitar algún filtro o cambiar la búsqueda.
                    </p>
                  </div>
                )}
              </div>
            )}

            {view === "DETAIL" && selectedDetail && (
              <DetailView
                detail={selectedDetail}
                onBack={handleGoBack}
                onAuthorClick={handleOpenProfile}
                onReportError={handleReportError}
                comments={comments.filter(
                  (c) => c.detailId === selectedDetail.id,
                )}
                onAddComment={handleAddComment}
                onDeleteComment={handleDeleteComment}
                onEditComment={handleEditComment}
                onCommentAuthorClick={handleOpenProfile}
                onToggleVote={handleToggleVote}
                currentUser={currentUser}
                relatedDetails={details
                  .filter(
                    (d) =>
                      d.category === selectedDetail.category &&
                      d.id !== selectedDetail.id,
                  )
                  .slice(0, 4)}
                onSelectDetail={handleOpenDetail}
                isFavorite={favoriteIds.includes(selectedDetail.id)}
                onToggleFavorite={handleToggleFavorite}
                onViewPlans={() => goTo("SUBSCRIPTION")}
              />
            )}

            {view === "PROFILE" && selectedAuthor && (
              <UserProfile
                author={selectedAuthor}
                authorDetails={
                  authorDetails ??
                  details.filter((d) => d.author.id === selectedAuthor.id)
                }
                onBack={handleGoBack}
                onSelectDetail={handleOpenDetail}
                favoriteIds={favoriteIds}
                onToggleFavorite={handleToggleFavorite}
                currentUserId={currentUser?.id}
                onToggleLike={handleToggleUserLike}
              />
            )}

            {view === "SUBSCRIPTION" && (
              <SubscriptionPage
                plans={plans}
                currentUser={currentUser}
                onSubscribe={handleSubscribe}
              />
            )}
            {view === "PAYMENT_RESULT" && currentUser && (
              <PaymentResultPage
                onPaid={() =>
                  AuthApi.me()
                    .then((u) => setCurrentUser(toClientUser(u)))
                    .catch(() => {})
                }
                onGoAccount={() => goTo("USER_DASHBOARD")}
                onGoPlans={() => goTo("SUBSCRIPTION")}
              />
            )}
            {view === "REFUND" && currentUser && (
              <RefundPage onBack={() => goTo("USER_DASHBOARD")} />
            )}
            {view === "MESSAGES" && canMessage && <MessagesPanel />}
            {view === "CONTACT" && <ContactPage config={config} />}
            {view === "AUTHORS" && (
              <AuthorsPage
                onAuthorClick={handleOpenProfile}
                currentUserId={currentUser?.id}
                onToggleLike={handleToggleUserLike}
              />
            )}
            {view === "LEGAL_TERMS" && (
              <LegalPage
                title="Términos de Uso"
                content={config.termsContent || ""}
              />
            )}
            {view === "LEGAL_PRIVACY" && (
              <LegalPage
                title="Política de Privacidad"
                content={config.privacyContent || ""}
              />
            )}
            {view === "AUTH" && (
              <AuthPage onLogin={handleLogin} onClose={() => goTo("HOME")} />
            )}
            {view === "RESET_PASSWORD" && (
              <AuthPage
                initialMode="reset"
                onLogin={handleLogin}
                onClose={() => goTo("HOME")}
              />
            )}
            {view === "USER_DASHBOARD" && currentUser && (
              <UserDashboard
                user={currentUser}
                savedDetails={savedDetails}
                onLogout={handleLogout}
                onUpdateUser={handleUpdateUser}
                onProfileImage={handleProfileImage}
                onSelectDetail={handleOpenDetail}
                onToggleFavorite={handleToggleFavorite}
                onExploreLibrary={() => goTo("HOME")}
                onOpenPublished={(slug) => navigate(detailPath(slug))}
                onRefreshUser={() =>
                  AuthApi.me()
                    .then((u) => setCurrentUser(toClientUser(u)))
                    .catch(() => {})
                }
                onGoToPlans={() => goTo("SUBSCRIPTION")}
                onGoToRefund={() => goTo("REFUND")}
              />
            )}
          </div>
          <Footer config={config} onNavigate={handleNavigate} />
        </div>
      </main>

      {view === "ADMIN" && isSuperadmin && (
        <AdminPanel
          reports={reports}
          config={config}
          plans={plans}
          onResolveReport={handleResolveReport}
          onConfigSaved={setConfig}
          onPlansSaved={setPlans}
          onClose={() => goTo("HOME")}
          onDetailsChanged={() => setDetailsVersion((v) => v + 1)}
          onCategoriesSaved={(c) => setCategories(c.map(toCategory))}
          onOpenProfile={(a) =>
            handleOpenProfile({ id: String(a.id), name: a.name } as Author)
          }
        />
      )}
    </div>
  );
}

export default App;