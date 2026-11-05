import React, { useState, useMemo, useEffect } from "react";
import {
  ConstructionDetail,
  ClientUser,
  ErrorReport,
  SiteConfig,
  PricingPlan,
  Tag,
  CategoryDef,
  PublicationStatus,
  AccessLevel,
  Author,
} from "../types";
import { DEFAULT_AVATAR } from "../constants";
import {
  Trash2,
  X,
  Save,
  LayoutDashboard,
  FileText,
  Users,
  Settings,
  UserCheck,
  AlertCircle,
  ShoppingCart,
  CreditCard,
  RotateCcw,
  Download,
  HardDrive,
  Database,
  Search,
  CheckSquare,
  Square,
  Eye,
  ArrowRight,
  MoreVertical,
  Shield,
  ChevronRight,
  BarChart3,
  TrendingUp,
  Globe,
  Lock,
  MessageSquare,
  Send,
  Activity,
  Smartphone,
  Monitor,
  LayoutGrid,
  List,
  Plus,
  FolderTree,
  Edit3,
  Image as ImageIcon,
  Briefcase,
  Calendar,
  UserPlus,
} from "lucide-react";
import {
  AdminApi,
  AdminBackups,
  AdminCommentsPage,
  AdminRefundApi,
  AdminStats,
  AdminUser,
  ApiCategory,
  PlanSlug,
  ReviewApi,
  SiteApi,
  SocialApi,
  StudioApi,
  StudioDetailSummary,
  errorText,
  fullDate,
  toConfig,
  toPlan,
} from "../services/api";
import ReviewInbox from "./ReviewInbox";
import DetailEditor from "./DetailEditor";
import AdminRefunds from "./AdminRefunds";
import AdminOrders from "./AdminOrders";
import FormMessage from "./FormMessage";
import { STATUS_LABELS } from "./studioLabels";

interface AdminPanelProps {
  reports?: ErrorReport[];
  config: SiteConfig;
  plans?: PricingPlan[];
  onResolveReport: (id: string) => void;
  onConfigSaved: (config: SiteConfig) => void;
  onPlansSaved: (plans: PricingPlan[]) => void;
  onClose: () => void;
  onDetailsChanged?: () => void;
  onCategoriesSaved?: (categories: ApiCategory[]) => void;
  onOpenProfile?: (author: { id: number; name: string }) => void;
}

type AdminView =
  | "dashboard"
  | "review"
  | "content"
  | "staff"
  | "clients"
  | "reports"
  | "settings"
  | "commerce"
  | "refunds"
  | "orders"
  | "categories"
  | "comments"
  | "backups"
  | "details_editor";

type Message = { type: "error" | "success"; text: string } | null;

const AdminPanel: React.FC<AdminPanelProps> = ({
  reports = [],
  config,
  plans = [],
  onResolveReport,
  onConfigSaved,
  onPlansSaved,
  onClose,
  onDetailsChanged,
  onCategoriesSaved,
  onOpenProfile,
}) => {
  const [currentView, setCurrentView] = useState<AdminView>("dashboard");

  // Detalles de colaboradores esperando aprobación (contador del menú)
  const [pendingReviews, setPendingReviews] = useState(0);
  const [pendingRefunds, setPendingRefunds] = useState(0);
  useEffect(() => {
    ReviewApi.list("pending")
      .then((res) => setPendingReviews(res.pending_count))
      .catch(() => {});
    AdminRefundApi.list("pending")
      .then((res) => setPendingRefunds(res.pending_count))
      .catch(() => {});
  }, []);

  // Forms & State
  const [configForm, setConfigForm] = useState<SiteConfig>(config);
  const [plansForm, setPlansForm] = useState<PricingPlan[]>(plans || []);
  // Descuentos de los pagos por 3 meses y anual (se guardan junto con los planes)
  const [discounts, setDiscounts] = useState<{ quarterly: string; yearly: string }>({
    quarterly: "15",
    yearly: "30",
  });
  const [selectedItems, setSelectedItems] = useState<Set<string>>(new Set());
  const [message, setMessage] = useState<Message>(null);
  const [isSaving, setIsSaving] = useState(false);

  // Datos reales del panel
  const [apiStats, setApiStats] = useState<AdminStats | null>(null);
  const [adminDetails, setAdminDetails] = useState<StudioDetailSummary[]>([]);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [categories, setCategories] = useState<ApiCategory[]>([]);

  // Details Editor State: null = cerrado, id null = detalle nuevo
  const [editorTarget, setEditorTarget] = useState<{ id: number | null } | null>(null);

  // Content View Mode
  const [contentViewMode, setContentViewMode] = useState<"grid" | "list">(
    "grid",
  );

  // CRM Inspector State
  const [selectedUser, setSelectedUser] = useState<AdminUser | null>(null);
  const [isEditingUser, setIsEditingUser] = useState(false);
  const [userForm, setUserForm] = useState<any>({});
  const [userPlan, setUserPlan] = useState<{
    plan: "free" | PlanSlug;
    months: number | null;
  }>({ plan: "free", months: null });
  const [userMessage, setUserMessage] = useState<Message>(null);

  // Alta de clientes: el admin crea la cuenta y le asigna un plan
  const emptyCreateForm = {
    name: "",
    email: "",
    plan: "free" as "free" | PlanSlug,
    months: null as number | null,
    password: "",
  };
  const [createOpen, setCreateOpen] = useState(false);
  const [createForm, setCreateForm] = useState(emptyCreateForm);
  const [createMessage, setCreateMessage] = useState<Message>(null);
  const [isCreating, setIsCreating] = useState(false);

  // Comentarios (moderación)
  const [commentsPage, setCommentsPage] = useState<AdminCommentsPage | null>(null);
  const [commentQuery, setCommentQuery] = useState("");
  const [commentSearch, setCommentSearch] = useState("");
  const [commentPageNo, setCommentPageNo] = useState(1);
  const [editingComment, setEditingComment] = useState<{ id: number; text: string } | null>(null);
  const [commentsMessage, setCommentsMessage] = useState<Message>(null);
  const [isSavingComment, setIsSavingComment] = useState(false);

  const loadComments = (q = commentSearch, page = commentPageNo) =>
    AdminApi.comments(q, page).then(setCommentsPage).catch((err) =>
      setCommentsMessage({ type: "error", text: errorText(err, "No se pudieron cargar los comentarios.") }),
    );

  const saveCommentEdit = async () => {
    if (!editingComment || !editingComment.text.trim()) return;
    setIsSavingComment(true);
    setCommentsMessage(null);
    try {
      await SocialApi.editComment(String(editingComment.id), editingComment.text);
      setEditingComment(null);
      setCommentsMessage({ type: "success", text: "Comentario actualizado." });
      await loadComments();
    } catch (err) {
      setCommentsMessage({ type: "error", text: errorText(err, "No se pudo guardar el comentario.") });
    } finally {
      setIsSavingComment(false);
    }
  };

  const deleteComment = async (id: number, hasReplies: boolean) => {
    if (!window.confirm(hasReplies ? "¿Eliminar este comentario? También se borran sus respuestas." : "¿Eliminar este comentario?")) return;
    setCommentsMessage(null);
    try {
      await SocialApi.deleteComment(String(id));
      setCommentsMessage({ type: "success", text: "Comentario eliminado." });
      await loadComments();
    } catch (err) {
      setCommentsMessage({ type: "error", text: errorText(err, "No se pudo eliminar el comentario.") });
    }
  };

  // Backups
  const [backupData, setBackupData] = useState<AdminBackups | null>(null);
  const [backupMessage, setBackupMessage] = useState<Message>(null);
  const [isBackingUp, setIsBackingUp] = useState(false);

  const loadBackups = () =>
    AdminApi.backups().then(setBackupData).catch((err) =>
      setBackupMessage({ type: "error", text: errorText(err, "No se pudieron cargar los backups.") }),
    );

  const createBackup = async () => {
    setBackupMessage(null);
    setIsBackingUp(true);
    try {
      const b = await AdminApi.createBackup();
      await loadBackups();
      setBackupMessage({ type: "success", text: `Backup creado: ${b.name}. Descargalo y guardalo también fuera del hosting.` });
    } catch (err) {
      setBackupMessage({ type: "error", text: errorText(err, "No se pudo crear el backup.") });
    } finally {
      setIsBackingUp(false);
    }
  };

  const deleteBackup = async (name: string) => {
    if (!window.confirm(`¿Eliminar ${name}? No se puede deshacer.`)) return;
    setBackupMessage(null);
    try {
      await AdminApi.deleteBackup(name);
      await loadBackups();
    } catch (err) {
      setBackupMessage({ type: "error", text: errorText(err, "No se pudo eliminar el backup.") });
    }
  };

  const formatSize = (bytes: number) =>
    bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;

  // Staff: crear un colaborador nuevo o convertir a un cliente que ya existe
  const [staffOpen, setStaffOpen] = useState(false);
  const [staffTab, setStaffTab] = useState<"new" | "existing">("new");
  const [staffForm, setStaffForm] = useState({ name: "", email: "", password: "" });
  const [staffMessage, setStaffMessage] = useState<Message>(null);
  const [isSavingStaff, setIsSavingStaff] = useState(false);
  const [clientQuery, setClientQuery] = useState("");
  const [clientResults, setClientResults] = useState<AdminUser[] | null>(null);

  const closeStaffModal = () => {
    if (isSavingStaff) return;
    setStaffOpen(false);
    setStaffForm({ name: "", email: "", password: "" });
    setClientQuery("");
    setClientResults(null);
    setStaffMessage(null);
  };

  const handleCreateStaff = async () => {
    setStaffMessage(null);
    setIsSavingStaff(true);
    try {
      const created = await AdminApi.createUser({
        name: staffForm.name.trim(),
        email: staffForm.email.trim(),
        plan: "free",
        months: null,
        password: staffForm.password ? staffForm.password : null,
        role: "colaborador",
      });
      setStaffOpen(false);
      setStaffForm({ name: "", email: "", password: "" });
      loadUsers("staff");
      setMessage(
        created.invite_sent === false
          ? { type: "error", text: `Se creó el colaborador ${created.email}, pero no se pudo enviar el mail para elegir la contraseña. Puede pedirlo desde "¿Olvidaste tu contraseña?".` }
          : {
              type: "success",
              text:
                created.invite_sent === true
                  ? `Colaborador creado. Le enviamos un mail a ${created.email} para que elija su contraseña.`
                  : `Colaborador creado: ${created.email}. Pasale la contraseña que definiste.`,
            },
      );
    } catch (err) {
      setStaffMessage({ type: "error", text: errorText(err, "No se pudo crear el colaborador.") });
    } finally {
      setIsSavingStaff(false);
    }
  };

  const searchClients = async () => {
    setStaffMessage(null);
    try {
      setClientResults(await AdminApi.users("usuario", clientQuery.trim()));
    } catch (err) {
      setStaffMessage({ type: "error", text: errorText(err, "No se pudo buscar.") });
    }
  };

  const promoteToStaff = async (u: AdminUser) => {
    setStaffMessage(null);
    setIsSavingStaff(true);
    try {
      await AdminApi.updateUser(u.id, {
        name: u.name,
        profession: u.profession ?? null,
        company: u.company ?? null,
        status: u.status,
        role: "colaborador",
      });
      setStaffOpen(false);
      setClientQuery("");
      setClientResults(null);
      loadUsers("staff");
      setMessage({ type: "success", text: `${u.name} ahora es colaborador y ya puede subir detalles.` });
    } catch (err) {
      setStaffMessage({ type: "error", text: errorText(err, "No se pudo convertir en colaborador.") });
    } finally {
      setIsSavingStaff(false);
    }
  };

  // Categorías y subcategorías
  const [newCategory, setNewCategory] = useState({ name: "", image_url: "" });
  const [newSub, setNewSub] = useState<{ parent_id: number | ""; name: string }>({
    parent_id: "",
    name: "",
  });
  const [categoryMessage, setCategoryMessage] = useState<Message>(null);
  const [isSavingCategory, setIsSavingCategory] = useState(false);

  const saveCategory = async (
    data: { name: string; parent_id?: number | null; image_url?: string | null },
    okText: string,
    onDone: () => void,
  ) => {
    setCategoryMessage(null);
    setIsSavingCategory(true);
    try {
      const list = await AdminApi.createCategory(data);
      setCategories(list);
      onCategoriesSaved?.(list); // el menú lateral del sitio también se actualiza
      onDone();
      setCategoryMessage({ type: "success", text: okText });
    } catch (err) {
      setCategoryMessage({
        type: "error",
        text: errorText(err, "No se pudo crear. Probá de nuevo."),
      });
    } finally {
      setIsSavingCategory(false);
    }
  };

  const loadDetails = () =>
    AdminApi.details().then(setAdminDetails).catch(console.error);
  const loadUsers = (role: "usuario" | "colaborador" | "staff") => {
    setUsers([]);
    AdminApi.users(role).then(setUsers).catch(console.error);
  };

  // Cada vista carga sus datos al entrar
  useEffect(() => {
    setMessage(null);
    // Al cambiar de sección se cierra la ficha lateral que había quedado abierta
    setSelectedUser(null);
    setIsEditingUser(false);
    if (currentView === "dashboard")
      AdminApi.stats().then(setApiStats).catch(console.error);
    if (currentView === "content") loadDetails();
    if (currentView === "clients") loadUsers("usuario");
    if (currentView === "staff") loadUsers("staff");
    if (currentView === "comments") {
      setCommentsMessage(null);
      setEditingComment(null);
      setCommentQuery("");
      setCommentSearch("");
      setCommentPageNo(1);
      setCommentsPage(null);
      loadComments("", 1);
    }
    if (currentView === "backups") {
      setBackupMessage(null);
      loadBackups();
    }
  }, [currentView]);

  useEffect(() => {
    SiteApi.categories().then(setCategories).catch(console.error);
  }, []);
  useEffect(() => setConfigForm(config), [config]);
  useEffect(() => setPlansForm(plans), [plans]);
  useEffect(() => {
    const periods = plans.find((p) => p.periods?.length)?.periods ?? [];
    const q = periods.find((p) => p.id === "quarterly")?.discountPercent;
    const y = periods.find((p) => p.id === "yearly")?.discountPercent;
    if (q !== undefined && y !== undefined)
      setDiscounts({ quarterly: String(q), yearly: String(y) });
  }, [plans]);

  // --- STATS (todos reales, de /api/admin/stats) ---
  const stats = useMemo(() => {
    const pendingReports = reports.length
      ? reports.filter((r) => r.status === "Pending").length
      : (apiStats?.pending_reports ?? 0);

    return {
      onlineUsers: apiStats?.online_users ?? 0,
      onlineStaff: apiStats?.online_staff ?? 0,
      newUsers: apiStats?.new_users_7d ?? 0,
      mrr: apiStats?.monthly_revenue_ars ?? 0,
      paidSubscriptions: apiStats?.paid_subscriptions ?? 0,
      activeClients: apiStats?.active_users ?? 0,
      total: apiStats?.published_details ?? 0,
      pendingReports,
      // Detalles abiertos por día (últimos 12 días)
      topDetails: apiStats?.top_details ?? [],
      trafficPoints: apiStats?.activity.map((a) => a.views) ?? [],
      recentUsers: (apiStats?.recent_users ?? []).map((u) => ({
        id: String(u.id),
        name: u.name,
        avatarUrl: u.avatar_url || DEFAULT_AVATAR,
        profession: u.profession ?? "",
        plan: u.plan,
      })),
    };
  }, [apiStats, reports]);

  // --- HANDLERS ---
  const handleSaveSettings = async () => {
    setIsSaving(true);
    setMessage(null);
    try {
      onConfigSaved(toConfig(await AdminApi.saveSettings(configForm)));
      setMessage({ type: "success", text: "Configuración guardada." });
    } catch (err) {
      setMessage({
        type: "error",
        text: errorText(err, "No se pudo guardar la configuración."),
      });
    } finally {
      setIsSaving(false);
    }
  };

  // Se guarda plan por plan; la API devuelve los 3 completos, con los
  // períodos y sus descuentos (antes se perdían los "periods" al guardar)
  const handleSavePlans = async () => {
    const quarterly = Number(discounts.quarterly);
    const yearly = Number(discounts.yearly);
    const validDiscount = (n: number) => Number.isInteger(n) && n >= 0 && n <= 90;
    if (!validDiscount(quarterly) || !validDiscount(yearly)) {
      setMessage({
        type: "error",
        text: "Los descuentos tienen que ser números enteros entre 0 y 90.",
      });
      return;
    }
    setIsSaving(true);
    setMessage(null);
    try {
      await AdminApi.updateDiscounts(quarterly, yearly);
      let saved: PricingPlan[] = plansForm;
      for (const plan of plansForm) {
        saved = (await AdminApi.updatePlan(plan)).map(toPlan);
      }
      onPlansSaved(saved);
      setPlansForm(saved);
      setMessage({ type: "success", text: "Planes actualizados." });
    } catch (err) {
      setMessage({
        type: "error",
        text: errorText(err, "No se pudieron guardar los planes."),
      });
    } finally {
      setIsSaving(false);
    }
  };

  const handleUpdatePlan = (
    index: number,
    field: keyof PricingPlan,
    value: any,
  ) => {
    const updated = [...plansForm];
    updated[index] = { ...updated[index], [field]: value };
    setPlansForm(updated);
  };

  const toggleSelection = (id: string) => {
    const newSet = new Set(selectedItems);
    if (newSet.has(id)) newSet.delete(id);
    else newSet.add(id);
    setSelectedItems(newSet);
  };

  const handleCreateNewDetail = () => {
    setMessage(null);
    setEditorTarget({ id: null });
    setCurrentView("details_editor");
  };

  const handleEditDetail = (id: number) => {
    setMessage(null);
    setEditorTarget({ id });
    setCurrentView("details_editor");
  };

  const handleDeleteDetail = async (id: number) => {
    if (!window.confirm("¿Eliminar este detalle? Se borran también sus imágenes y archivos.")) return;
    try {
      await StudioApi.remove(id);
      loadDetails();
      onDetailsChanged?.();
    } catch (err) {
      setMessage({ type: "error", text: errorText(err, "No se pudo eliminar el detalle.") });
    }
  };

  const handleCreateUser = async () => {
    setCreateMessage(null);
    setIsCreating(true);
    try {
      const created = await AdminApi.createUser({
        name: createForm.name.trim(),
        email: createForm.email.trim(),
        plan: createForm.plan,
        months: createForm.plan === "free" ? null : createForm.months,
        password: createForm.password ? createForm.password : null,
      });
      setCreateOpen(false);
      setCreateForm(emptyCreateForm);
      loadUsers("usuario");
      setMessage(
        created.invite_sent === false
          ? {
              type: "error",
              text: `Se creó la cuenta de ${created.email}, pero no se pudo enviar el mail para elegir la contraseña. La persona puede pedirlo desde "¿Olvidaste tu contraseña?".`,
            }
          : {
              type: "success",
              text:
                created.invite_sent === true
                  ? `Cliente creado. Le enviamos un mail a ${created.email} para que elija su contraseña.`
                  : `Cliente creado: ${created.email}. Pasale la contraseña que definiste.`,
            },
      );
    } catch (err) {
      setCreateMessage({ type: "error", text: errorText(err, "No se pudo crear el cliente.") });
    } finally {
      setIsCreating(false);
    }
  };

  // --- CRM HELPERS ---
  const handleOpenUserInspector = (user: AdminUser) => {
    setSelectedUser(user);
    setUserForm({
      name: user.name,
      profession: user.profession ?? "",
      company: user.company ?? "",
      status: user.status,
      role: user.roles.includes("colaborador") ? "colaborador" : "usuario",
    });
    setUserPlan({ plan: user.plan.slug, months: null });
    setUserMessage(null);
    setIsEditingUser(false);
  };

  // Si cambió el rol, la persona puede pasar de Clientes a Staff (o al revés)
  const refreshAfterUserChange = (u: AdminUser) => {
    setSelectedUser(u);
    if (currentView === "clients" || currentView === "staff") {
      loadUsers(currentView === "staff" ? "staff" : "usuario");
    }
  };

  const handleSaveUser = async () => {
    if (!selectedUser) return;
    setUserMessage(null);
    try {
      const u = await AdminApi.updateUser(selectedUser.id, {
        name: userForm.name,
        profession: userForm.profession || null,
        company: userForm.company || null,
        status: userForm.status,
        role: userForm.role,
      });
      refreshAfterUserChange(u);
      setIsEditingUser(false);
      setUserMessage({ type: "success", text: "Cambios guardados." });
    } catch (err) {
      setUserMessage({ type: "error", text: errorText(err, "No se pudieron guardar los cambios.") });
    }
  };

  const handleSetUserPlan = async () => {
    if (!selectedUser) return;
    setUserMessage(null);
    try {
      const u = await AdminApi.setUserPlan(selectedUser.id, userPlan.plan, userPlan.months);
      refreshAfterUserChange(u);
      setUserMessage({ type: "success", text: `Plan ${u.plan.name} asignado.` });
    } catch (err) {
      setUserMessage({ type: "error", text: errorText(err, "No se pudo asignar el plan.") });
    }
  };

  // --- RENDER HELPERS ---
  const renderSidebarItem = (
    view: AdminView,
    icon: React.ReactNode,
    label: string,
    badge?: number,
  ) => (
    <button
      onClick={() => {
        setCurrentView(view);
      }}
      className={`w-full flex items-center justify-between px-4 py-3 rounded-xl transition-all font-medium mb-1 ${
        currentView === view ||
        (view === "content" && currentView === "details_editor")
          ? "bg-primary-400 text-slate-900 shadow-lg shadow-primary-400/20 font-bold"
          : "text-slate-400 hover:bg-slate-800 hover:text-slate-200"
      }`}
    >
      <div className="flex items-center gap-3">
        {icon}
        <span>{label}</span>
      </div>
      {badge ? (
        <span
          className={`text-xs px-2 py-0.5 rounded-full font-bold ${currentView === view ? "bg-slate-900 text-primary-400" : "bg-primary-500 text-slate-900"}`}
        >
          {badge}
        </span>
      ) : null}
    </button>
  );

  // SVG Chart Helper
  const renderTrafficChart = () => {
    const points = stats.trafficPoints;
    if (points.length < 2) return null;
    const width = 1000;
    const height = 300;
    const stepX = width / (points.length - 1);
    // El día con más actividad llega al 90% del alto
    const max = Math.max(...points, 1);
    const yOf = (p: number) => height - (p / max) * height * 0.9;

    let pathD = `M0,${height} `;
    points.forEach((p, i) => {
      pathD += `L${i * stepX},${yOf(p)} `;
    });
    pathD += `L${width},${height} Z`;

    let linePath = "";
    points.forEach((p, i) => {
      linePath += `${i === 0 ? "M" : "L"}${i * stepX},${yOf(p)} `;
    });

    return (
      <svg
        viewBox={`0 0 ${width} ${height}`}
        className="w-full h-full overflow-visible preserve-3d"
      >
        <defs>
          <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="#facc15" stopOpacity="0.5" />
            <stop offset="100%" stopColor="#facc15" stopOpacity="0" />
          </linearGradient>
        </defs>
        <path d={pathD} fill="url(#chartGradient)" />
        <path
          d={linePath}
          fill="none"
          stroke="#facc15"
          strokeWidth="4"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        {points.map((p, i) => (
          <circle
            key={i}
            cx={i * stepX}
            cy={yOf(p)}
            r="6"
            fill="#facc15"
            className="animate-pulse origin-center"
          />
        ))}
      </svg>
    );
  };

  const renderSlideOver = () => {
    if (!selectedUser) return null;
    const isClient = !selectedUser.roles.includes("colaborador");
    const fieldClass =
      "w-full p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm";

    return (
      <div className="absolute top-0 right-0 h-full w-96 bg-white dark:bg-dark-surface shadow-2xl border-l border-slate-200 dark:border-dark-border z-20 overflow-y-auto animate-in slide-in-from-right duration-300">
        <div className="p-6">
          <div className="flex justify-between items-start mb-6">
            <h3 className="font-bold text-xl dark:text-white">
              Ficha de Usuario
            </h3>
            <button
              onClick={() => setSelectedUser(null)}
              className="p-1 hover:bg-slate-100 rounded-full"
            >
              <X size={20} />
            </button>
          </div>

          <div className="flex flex-col items-center mb-6">
            <img
              src={selectedUser.avatar_url || DEFAULT_AVATAR}
              className="w-24 h-24 rounded-full mb-3 object-cover border-4 border-slate-100 dark:border-dark-bg"
            />
            <h2 className="text-xl font-bold dark:text-white">
              {selectedUser.name}
            </h2>
            <p className="text-sm text-slate-500">
              {selectedUser.profession}
            </p>

            {!isEditingUser ? (
              <button
                onClick={() => {
                  setIsEditingUser(true);
                  setUserMessage(null);
                }}
                className="mt-4 px-4 py-2 bg-slate-100 dark:bg-dark-bg text-slate-600 dark:text-slate-300 rounded-lg text-sm font-bold flex items-center gap-2 hover:bg-slate-200"
              >
                <Edit3 size={14} /> Editar Perfil
              </button>
            ) : (
              <div className="w-full space-y-3 mt-4">
                <input
                  value={userForm.name}
                  onChange={(e) => setUserForm({ ...userForm, name: e.target.value })}
                  placeholder="Nombre"
                  className={fieldClass}
                />
                <input
                  value={userForm.profession}
                  onChange={(e) => setUserForm({ ...userForm, profession: e.target.value })}
                  placeholder="Profesión"
                  className={fieldClass}
                />
                <input
                  value={userForm.company}
                  onChange={(e) => setUserForm({ ...userForm, company: e.target.value })}
                  placeholder="Estudio o empresa"
                  className={fieldClass}
                />
                <div className="grid grid-cols-2 gap-2">
                  <select
                    value={userForm.status}
                    onChange={(e) => setUserForm({ ...userForm, status: e.target.value })}
                    className={fieldClass}
                  >
                    <option value="active">Activo</option>
                    <option value="suspended">Suspendido</option>
                  </select>
                  <select
                    value={userForm.role}
                    onChange={(e) => setUserForm({ ...userForm, role: e.target.value })}
                    className={fieldClass}
                  >
                    <option value="usuario">Usuario</option>
                    <option value="colaborador">Colaborador</option>
                  </select>
                </div>
                <div className="flex gap-2 justify-center">
                  <button
                    onClick={() => setIsEditingUser(false)}
                    className="px-3 py-1.5 bg-slate-100 rounded text-sm font-bold"
                  >
                    Cancelar
                  </button>
                  <button
                    onClick={handleSaveUser}
                    className="px-3 py-1.5 bg-primary-400 rounded text-sm font-bold"
                  >
                    Guardar
                  </button>
                </div>
              </div>
            )}
            {userMessage && (
              <div className="mt-3">
                <FormMessage {...userMessage} />
              </div>
            )}
          </div>

          <div className="space-y-4">
            <div className="bg-slate-50 dark:bg-dark-bg p-4 rounded-xl">
              <h4 className="text-xs font-bold text-slate-400 uppercase mb-2">
                Suscripción
              </h4>
              <div className="flex justify-between mb-1">
                <span className="text-sm dark:text-slate-300">
                  Plan Actual
                </span>
                <span className="text-sm font-bold text-primary-500">
                  {selectedUser.plan.name}
                  {selectedUser.subscription?.provider === "manual" && " (manual)"}
                </span>
              </div>
              {selectedUser.subscription?.ends_at && (
                <div className="flex justify-between mb-1">
                  <span className="text-sm dark:text-slate-300">Vence</span>
                  <span className="text-sm font-bold dark:text-white">
                    {new Date(selectedUser.subscription.ends_at).toLocaleDateString("es-AR")}
                  </span>
                </div>
              )}
              <div className="flex justify-between">
                <span className="text-sm dark:text-slate-300">Estado</span>
                <span className={`text-sm font-bold ${selectedUser.status === "active" ? "text-green-500" : "text-red-500"}`}>
                  {selectedUser.status === "active" ? "Activo" : "Suspendido"}
                </span>
              </div>
              {/* Plan manual: cortesías, canjes o pruebas */}
              <div className="flex gap-2 mt-3 pt-3 border-t border-slate-200 dark:border-dark-border">
                <select
                  value={userPlan.plan}
                  onChange={(e) => setUserPlan({ ...userPlan, plan: e.target.value as "free" | PlanSlug })}
                  className={fieldClass}
                >
                  <option value="free">Free</option>
                  <option value="estudiante">Estudiante</option>
                  <option value="pro">Pro</option>
                </select>
                <select
                  value={userPlan.months ?? ""}
                  disabled={userPlan.plan === "free"}
                  onChange={(e) => setUserPlan({ ...userPlan, months: e.target.value ? Number(e.target.value) : null })}
                  className={fieldClass}
                >
                  <option value="">Sin vencimiento</option>
                  <option value="1">1 mes</option>
                  <option value="3">3 meses</option>
                  <option value="12">12 meses</option>
                </select>
                <button
                  onClick={handleSetUserPlan}
                  className="px-3 py-1.5 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold"
                >
                  Asignar
                </button>
              </div>
            </div>
            <div className="bg-slate-50 dark:bg-dark-bg p-4 rounded-xl">
              <h4 className="text-xs font-bold text-slate-400 uppercase mb-2">
                Contacto
              </h4>
              <p className="text-sm dark:text-slate-300 break-all">
                {selectedUser.email}
              </p>
            </div>
            {!isClient && (
              <div className="bg-slate-50 dark:bg-dark-bg p-4 rounded-xl">
                <h4 className="text-xs font-bold text-slate-400 uppercase mb-2">
                  Desempeño
                </h4>
                <div className="flex justify-between mb-1">
                  <span className="text-sm dark:text-slate-300">Equipo</span>
                  <span className="text-sm font-bold dark:text-white">
                    {selectedUser.company || "—"}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-sm dark:text-slate-300">Detalles publicados</span>
                  <span className="text-sm font-bold dark:text-white">
                    {selectedUser.details_count}
                  </span>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    );
  };

  return (
    <div className="fixed inset-0 z-50 bg-slate-900/90 backdrop-blur-md flex items-center justify-center p-4 overflow-hidden">
      <div className="bg-slate-50 dark:bg-dark-bg w-full max-w-[95vw] h-[90vh] rounded-3xl shadow-2xl border border-slate-700 flex overflow-hidden relative">
        {/* SIDEBAR */}
        <div className="w-64 bg-slate-900 border-r border-slate-800 p-6 flex flex-col hidden lg:flex shrink-0 overflow-y-auto">
          <div className="flex items-center gap-3 mb-10 text-white px-2">
            <div className="w-8 h-8 bg-primary-400 rounded-lg flex items-center justify-center text-slate-900 font-bold shadow shadow-primary-400/50">
              C
            </div>
            <span className="font-bold text-lg tracking-tight">
              EnDetalle CMS
            </span>
          </div>
          <nav className="flex-1 space-y-6">
            <div>
              <div className="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4 px-2">
                General
              </div>
              {renderSidebarItem(
                "dashboard",
                <LayoutDashboard size={18} />,
                "Dashboard",
              )}
              {renderSidebarItem(
                "review",
                <CheckSquare size={18} />,
                "Aprobaciones",
                pendingReviews,
              )}
              {renderSidebarItem("content", <FileText size={18} />, "Detalles")}
              {renderSidebarItem(
                "categories",
                <FolderTree size={18} />,
                "Categorías",
              )}
            </div>
            <div>
              <div className="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4 px-2">
                Comunidad
              </div>
              {renderSidebarItem(
                "clients",
                <UserCheck size={18} />,
                "Clientes",
                stats.activeClients,
              )}
              {renderSidebarItem("staff", <Users size={18} />, "Staff")}
              {renderSidebarItem(
                "reports",
                <AlertCircle size={18} />,
                "Reportes",
                stats.pendingReports,
              )}
              {renderSidebarItem(
                "comments",
                <MessageSquare size={18} />,
                "Comentarios",
              )}
            </div>
            <div>
              <div className="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4 px-2">
                Negocio
              </div>
              {renderSidebarItem(
                "commerce",
                <ShoppingCart size={18} />,
                "Planes y Precios",
              )}
              {renderSidebarItem(
                "orders",
                <CreditCard size={18} />,
                "Pagos y órdenes",
              )}
              {renderSidebarItem(
                "refunds",
                <RotateCcw size={18} />,
                "Reembolsos",
                pendingRefunds,
              )}
              {renderSidebarItem(
                "settings",
                <Settings size={18} />,
                "Configuración",
              )}
              {renderSidebarItem(
                "backups",
                <HardDrive size={18} />,
                "Backups",
              )}
            </div>
          </nav>
          <button
            onClick={onClose}
            className="w-full flex items-center gap-3 px-4 py-3 text-red-400 hover:bg-red-500/10 rounded-xl transition-colors font-medium mt-4"
          >
            <X size={18} />
            <span>Cerrar Panel</span>
          </button>
        </div>

        {/* MAIN CONTENT */}
        <div className="flex-1 flex flex-col bg-slate-50 dark:bg-dark-surface overflow-hidden relative">
          {/* Mobile Header */}
          <div className="lg:hidden p-4 bg-slate-900 text-white flex justify-between items-center shrink-0">
            <span className="font-bold">EnDetalle CMS</span>
            <button onClick={onClose}>
              <X size={20} />
            </button>
          </div>

          <div
            className={`flex-1 p-6 md:p-8 relative scrollbar-thin ${currentView === "dashboard" ? "overflow-y-auto lg:overflow-hidden" : "overflow-y-auto"}`}
          >
            {/* --- DASHBOARD VIEW --- */}
            {currentView === "dashboard" && (
              <div className="space-y-8 max-w-7xl mx-auto animate-in fade-in duration-500 lg:h-full lg:flex lg:flex-col">
                {/* Header */}
                <div className="flex justify-between items-center">
                  <div>
                    <h2 className="text-3xl font-bold text-slate-900 dark:text-white mb-1">
                      Visión General
                    </h2>
                    <p className="text-slate-500 dark:text-slate-400 text-sm">
                      Bienvenido de nuevo. Aquí tienes lo que sucede hoy.
                    </p>
                  </div>
                </div>

                {/* ONLINE ACTIVITY WIDGET */}
                <div className="bg-slate-900 text-white p-6 rounded-2xl flex justify-around items-center shadow-lg">
                  <div className="text-center">
                    <div className="text-3xl font-bold text-primary-400">
                      {stats.onlineUsers}
                    </div>
                    <div className="text-xs font-bold uppercase tracking-wider text-slate-400">
                      Usuarios Online
                    </div>
                  </div>
                  <div className="h-10 w-px bg-slate-700"></div>
                  <div className="text-center">
                    <div className="text-3xl font-bold text-blue-400">
                      {stats.onlineStaff}
                    </div>
                    <div className="text-xs font-bold uppercase tracking-wider text-slate-400">
                      Staff Online
                    </div>
                  </div>
                  <div className="h-10 w-px bg-slate-700"></div>
                  <div className="text-center">
                    <div className="text-3xl font-bold text-green-400">
                      {stats.newUsers}
                    </div>
                    <div className="text-xs font-bold uppercase tracking-wider text-slate-400">
                      Registros (7 días)
                    </div>
                  </div>
                </div>

                {/* KPI GRID */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                  <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border shadow-sm flex flex-col justify-between">
                    <div className="flex justify-between items-start mb-4">
                      <div className="p-3 bg-green-50 dark:bg-green-900/20 text-green-600 rounded-xl">
                        <ShoppingCart size={20} />
                      </div>
                      <span className="text-xs font-bold text-green-600 bg-green-100 dark:bg-green-900/30 px-2 py-1 rounded-full">
                        {stats.paidSubscriptions} pagas
                      </span>
                    </div>
                    <div>
                      <div className="text-3xl font-bold text-slate-900 dark:text-white mb-1">
                        ${stats.mrr.toLocaleString("es-AR")}
                      </div>
                      <div className="text-xs text-slate-500 font-bold uppercase tracking-wider">
                        Ingreso Mensual (ARS)
                      </div>
                    </div>
                  </div>
                  <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border shadow-sm flex flex-col justify-between">
                    <div className="flex justify-between items-start mb-4">
                      <div className="p-3 bg-blue-50 dark:bg-blue-900/20 text-blue-600 rounded-xl">
                        <Users size={20} />
                      </div>
                      <span className="text-xs font-bold text-blue-600 bg-blue-100 dark:bg-blue-900/30 px-2 py-1 rounded-full">
                        +{stats.newUsers}
                      </span>
                    </div>
                    <div>
                      <div className="text-3xl font-bold text-slate-900 dark:text-white mb-1">
                        {stats.activeClients}
                      </div>
                      <div className="text-xs text-slate-500 font-bold uppercase tracking-wider">
                        Clientes Activos
                      </div>
                    </div>
                  </div>
                  <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border shadow-sm flex flex-col justify-between">
                    <div className="flex justify-between items-start mb-4">
                      <div className="p-3 bg-purple-50 dark:bg-purple-900/20 text-purple-600 rounded-xl">
                        <FileText size={20} />
                      </div>
                      <span className="text-xs font-bold text-purple-600 bg-purple-100 dark:bg-purple-900/30 px-2 py-1 rounded-full">
                        Publicados
                      </span>
                    </div>
                    <div>
                      <div className="text-3xl font-bold text-slate-900 dark:text-white mb-1">
                        {stats.total}
                      </div>
                      <div className="text-xs text-slate-500 font-bold uppercase tracking-wider">
                        Detalles Constructivos
                      </div>
                    </div>
                  </div>
                  <div className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border shadow-sm flex flex-col justify-between relative overflow-hidden">
                    {stats.pendingReports > 0 && (
                      <div className="animate-pulse w-3 h-3 bg-red-500 rounded-full absolute top-4 right-4 shadow-[0_0_10px_red]"></div>
                    )}
                    <div className="flex justify-between items-start mb-4">
                      <div className="p-3 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-600 rounded-xl">
                        <AlertCircle size={20} />
                      </div>
                      <span className="text-xs font-bold text-yellow-600 bg-yellow-100 dark:bg-yellow-900/30 px-2 py-1 rounded-full">
                        {stats.pendingReports}
                      </span>
                    </div>
                    <div>
                      <div className="text-3xl font-bold text-slate-900 dark:text-white mb-1">
                        {stats.pendingReports}
                      </div>
                      <div className="text-xs text-slate-500 font-bold uppercase tracking-wider">
                        Reportes Pendientes
                      </div>
                    </div>
                  </div>
                </div>

                {/* Recent Activity */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:flex-1 lg:min-h-0">
                  <div className="lg:col-span-2 bg-white dark:bg-dark-card p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm flex flex-col h-96 lg:h-auto lg:min-h-0">
                    <div className="flex justify-between items-center mb-6">
                      <h3 className="text-lg font-bold dark:text-white flex items-center gap-2">
                        <Activity className="text-primary-500" size={20} />{" "}
                        Detalles abiertos (últimos 12 días)
                      </h3>
                    </div>
                    <div className="flex-1 min-h-0 w-full flex flex-col lg:flex-row gap-6">
                      <div className="flex-1 min-h-[180px] min-w-0 relative">
                        {renderTrafficChart()}
                      </div>
                      <div className="lg:w-60 shrink-0 lg:border-l lg:border-slate-100 lg:dark:border-dark-border lg:pl-6">
                        <h4 className="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">
                          Más vistos (Top 5)
                        </h4>
                        <ol className="space-y-2.5">
                          {stats.topDetails.map((d, i) => (
                            <li key={d.id} className="flex items-center gap-3">
                              <span className="w-6 h-6 shrink-0 rounded-full bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 text-xs font-bold flex items-center justify-center">
                                {i + 1}
                              </span>
                              <span
                                className="flex-1 min-w-0 text-sm font-medium dark:text-white truncate"
                                title={d.title}
                              >
                                {d.title}
                              </span>
                              <span className="shrink-0 flex items-center gap-1 text-xs text-slate-500 font-bold">
                                <Eye size={13} /> {d.views}
                              </span>
                            </li>
                          ))}
                          {stats.topDetails.length === 0 && (
                            <li className="text-sm text-slate-400">
                              Todavía no hay visitas.
                            </li>
                          )}
                        </ol>
                        <p className="text-[11px] text-slate-400 mt-3">
                          Cuenta personas distintas que abrieron cada detalle.
                        </p>
                      </div>
                    </div>
                  </div>
                  <div className="bg-white dark:bg-dark-card p-6 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm overflow-hidden">
                    <h3 className="text-lg font-bold dark:text-white mb-4">
                      Últimos Registros
                    </h3>
                    <div className="space-y-4">
                      {stats.recentUsers.map((user) => (
                        <div key={user.id} className="flex items-center gap-3">
                          <img
                            src={user.avatarUrl}
                            className="w-10 h-10 rounded-full bg-slate-200 object-cover"
                          />
                          <div className="flex-1 min-w-0">
                            <h4 className="text-sm font-bold dark:text-white truncate">
                              {user.name}
                            </h4>
                            <p className="text-xs text-slate-500 truncate">
                              {user.profession}
                            </p>
                          </div>
                          <span className="text-xs bg-slate-100 dark:bg-dark-bg px-2 py-1 rounded font-bold">
                            {user.plan}
                          </span>
                        </div>
                      ))}
                      {stats.recentUsers.length === 0 && (
                        <p className="text-slate-400 text-sm">
                          No hay registros recientes.
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* --- CONTENT VIEW --- */}
            {/* --- APPROVALS VIEW --- */}
            {currentView === "review" && (
              <ReviewInbox
                onPendingCount={setPendingReviews}
                onChanged={onDetailsChanged}
              />
            )}

            {currentView === "content" && (
              <div className="space-y-6">
                <div className="flex justify-between items-center">
                  <h2 className="text-2xl font-bold dark:text-white">
                    Gestión de Detalles
                  </h2>
                  <div className="flex gap-2">
                    <button
                      onClick={() => setContentViewMode("grid")}
                      className={`p-2 rounded-lg ${contentViewMode === "grid" ? "bg-primary-400 text-slate-900" : "bg-slate-100 text-slate-500"}`}
                    >
                      <LayoutGrid size={20} />
                    </button>
                    <button
                      onClick={() => setContentViewMode("list")}
                      className={`p-2 rounded-lg ${contentViewMode === "list" ? "bg-primary-400 text-slate-900" : "bg-slate-100 text-slate-500"}`}
                    >
                      <List size={20} />
                    </button>
                    <button
                      onClick={handleCreateNewDetail}
                      className="bg-slate-900 text-white px-4 py-2 rounded-lg font-bold text-sm flex items-center gap-2"
                    >
                      <Plus size={16} /> Nuevo
                    </button>
                  </div>
                </div>

                {message && <FormMessage {...message} />}

                {contentViewMode === "list" ? (
                  <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border overflow-hidden">
                    <table className="w-full text-left">
                      <thead className="bg-slate-50 dark:bg-dark-bg border-b border-slate-200 dark:border-dark-border">
                        <tr>
                          <th className="p-4 text-xs font-bold text-slate-500 uppercase">
                            Detalle
                          </th>
                          <th className="p-4 text-xs font-bold text-slate-500 uppercase">
                            Categoría
                          </th>
                          <th className="p-4 text-xs font-bold text-slate-500 uppercase">
                            Autor
                          </th>
                          <th className="p-4 text-xs font-bold text-slate-500 uppercase text-right">
                            Acciones
                          </th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100 dark:divide-dark-border">
                        {adminDetails.map((detail) => (
                          <tr
                            key={detail.id}
                            className="hover:bg-slate-50 dark:hover:bg-dark-bg/50"
                          >
                            <td className="p-4 font-bold dark:text-white">
                              {detail.title}
                              <span className={`ml-2 align-middle text-[10px] px-2 py-0.5 rounded-full font-bold uppercase ${STATUS_LABELS[detail.status].className}`}>
                                {STATUS_LABELS[detail.status].label}
                              </span>
                            </td>
                            <td className="p-4 text-sm text-slate-600 dark:text-slate-300">
                              {detail.category}
                            </td>
                            <td className="p-4 text-sm text-slate-600 dark:text-slate-300">
                              {detail.author?.name ?? "—"}
                            </td>
                            <td className="p-4 text-right flex justify-end gap-2">
                              <button
                                onClick={() => handleEditDetail(detail.id)}
                                className="text-blue-500 hover:bg-blue-50 p-1.5 rounded"
                              >
                                <Edit3 size={16} />
                              </button>
                              <button
                                onClick={() => handleDeleteDetail(detail.id)}
                                className="text-red-500 hover:bg-red-50 p-1.5 rounded"
                              >
                                <Trash2 size={16} />
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    {adminDetails.map((detail) => (
                      <div
                        key={detail.id}
                        className="bg-white dark:bg-dark-card rounded-xl border border-slate-200 dark:border-dark-border overflow-hidden group"
                      >
                        <div className="h-32 overflow-hidden bg-slate-200 relative">
                          {detail.cover_url && (
                            <img
                              src={detail.cover_url}
                              className="w-full h-full object-cover group-hover:scale-105 transition-transform"
                            />
                          )}
                          <span className={`absolute top-2 left-2 text-[10px] px-2 py-0.5 rounded-full font-bold uppercase ${STATUS_LABELS[detail.status].className}`}>
                            {STATUS_LABELS[detail.status].label}
                          </span>
                          <div className="absolute top-2 right-2 flex gap-1">
                            <button
                              onClick={() => handleEditDetail(detail.id)}
                              className="p-1 bg-white/90 rounded text-blue-600 hover:text-blue-700"
                            >
                              <Edit3 size={14} />
                            </button>
                            <button
                              onClick={() => handleDeleteDetail(detail.id)}
                              className="p-1 bg-white/90 rounded text-red-600 hover:text-red-700"
                            >
                              <Trash2 size={14} />
                            </button>
                          </div>
                        </div>
                        <div className="p-4">
                          <h3 className="font-bold text-slate-900 dark:text-white text-sm line-clamp-1">
                            {detail.title}
                          </h3>
                          <p className="text-xs text-slate-500 mt-1">
                            {detail.category}
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* --- EDITOR VIEW --- */}
            {currentView === "details_editor" && editorTarget && (
              <div className="max-w-4xl mx-auto">
                <DetailEditor
                  key={editorTarget.id ?? "new"}
                  detailId={editorTarget.id}
                  categories={categories}
                  isAdmin
                  onOpenProfile={onOpenProfile}
                  backLabel="Detalles"
                  onBack={() => {
                    setCurrentView("content");
                    setEditorTarget(null);
                  }}
                  onChanged={() => {
                    loadDetails();
                    onDetailsChanged?.();
                  }}
                />
              </div>
            )}

            {/* --- CLIENTS VIEW --- */}
            {currentView === "clients" && (
              <div className="space-y-6">
                <div className="flex items-center justify-between gap-4 flex-wrap">
                  <h2 className="text-2xl font-bold dark:text-white">
                    Gestión de Clientes
                  </h2>
                  <button
                    onClick={() => {
                      setCreateMessage(null);
                      setMessage(null);
                      setCreateOpen(true);
                    }}
                    className="flex items-center gap-2 px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold"
                  >
                    <Plus size={16} /> Crear cliente
                  </button>
                </div>
                {message && <FormMessage {...message} />}
                <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border overflow-hidden">
                  <table className="w-full text-left">
                    <thead className="bg-slate-50 dark:bg-dark-bg border-b border-slate-200 dark:border-dark-border">
                      <tr>
                        <th className="p-4 text-xs font-bold text-slate-500 uppercase">
                          Nombre
                        </th>
                        <th className="p-4 text-xs font-bold text-slate-500 uppercase">
                          Email
                        </th>
                        <th className="p-4 text-xs font-bold text-slate-500 uppercase">
                          Plan
                        </th>
                        <th className="p-4 text-xs font-bold text-slate-500 uppercase">
                          Estado
                        </th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-dark-border">
                      {users.map((client) => (
                        <tr
                          key={client.id}
                          onClick={() => handleOpenUserInspector(client)}
                          className="hover:bg-slate-50 dark:hover:bg-dark-bg/50 cursor-pointer"
                        >
                          <td className="p-4 font-bold dark:text-white flex items-center gap-3">
                            <img
                              src={client.avatar_url || DEFAULT_AVATAR}
                              className="w-8 h-8 rounded-full object-cover"
                            />
                            {client.name}
                          </td>
                          <td className="p-4 text-sm text-slate-600 dark:text-slate-300">
                            {client.email}
                          </td>
                          <td className="p-4">
                            <span className="bg-slate-100 dark:bg-slate-800 text-xs font-bold px-2 py-1 rounded">
                              {client.plan.name}
                            </span>
                          </td>
                          <td className="p-4">
                            <span
                              className={`text-xs font-bold px-2 py-1 rounded ${client.status === "active" ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"}`}
                            >
                              {client.status === "active" ? "Activo" : "Suspendido"}
                            </span>
                          </td>
                        </tr>
                      ))}
                      {users.length === 0 && (
                        <tr>
                          <td colSpan={4} className="p-6 text-center text-sm text-slate-400">
                            No hay clientes para mostrar.
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* --- Crear cliente (modal) --- */}
            {currentView === "clients" && createOpen && (
              <div
                className="fixed inset-0 z-30 bg-black/40 flex items-center justify-center p-4"
                onClick={() => !isCreating && setCreateOpen(false)}
              >
                <div
                  className="w-full max-w-md bg-white dark:bg-dark-surface rounded-2xl shadow-2xl border border-slate-200 dark:border-dark-border p-6 space-y-4"
                  onClick={(e) => e.stopPropagation()}
                >
                  <div className="flex justify-between items-start">
                    <h3 className="font-bold text-xl dark:text-white">Crear cliente</h3>
                    <button
                      onClick={() => setCreateOpen(false)}
                      disabled={isCreating}
                      className="p-1 hover:bg-slate-100 rounded-full"
                    >
                      <X size={20} />
                    </button>
                  </div>
                  <form
                    className="space-y-3"
                    onSubmit={(e) => {
                      e.preventDefault();
                      handleCreateUser();
                    }}
                  >
                    {(() => {
                      const field =
                        "w-full p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm";
                      const label = "block text-xs font-bold text-slate-500 uppercase mb-1";
                      return (
                        <>
                          <div>
                            <label className={label}>Nombre</label>
                            <input
                              required
                              value={createForm.name}
                              onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })}
                              className={field}
                            />
                          </div>
                          <div>
                            <label className={label}>Email</label>
                            <input
                              required
                              type="email"
                              value={createForm.email}
                              onChange={(e) => setCreateForm({ ...createForm, email: e.target.value })}
                              className={field}
                            />
                          </div>
                          <div className="flex gap-2">
                            <div className="flex-1">
                              <label className={label}>Plan</label>
                              <select
                                value={createForm.plan}
                                onChange={(e) =>
                                  setCreateForm({
                                    ...createForm,
                                    plan: e.target.value as "free" | PlanSlug,
                                    months: e.target.value === "free" ? null : createForm.months,
                                  })
                                }
                                className={field}
                              >
                                <option value="free">Free</option>
                                <option value="estudiante">Estudiante</option>
                                <option value="pro">Pro</option>
                              </select>
                            </div>
                            <div className="flex-1">
                              <label className={label}>Duración</label>
                              <select
                                value={createForm.months ?? ""}
                                disabled={createForm.plan === "free"}
                                onChange={(e) =>
                                  setCreateForm({
                                    ...createForm,
                                    months: e.target.value ? Number(e.target.value) : null,
                                  })
                                }
                                className={field}
                              >
                                <option value="">Sin vencimiento</option>
                                <option value="1">1 mes</option>
                                <option value="3">3 meses</option>
                                <option value="12">12 meses</option>
                              </select>
                            </div>
                          </div>
                          <div>
                            <label className={label}>Contraseña (opcional)</label>
                            <input
                              type="text"
                              autoComplete="off"
                              minLength={8}
                              value={createForm.password}
                              onChange={(e) => setCreateForm({ ...createForm, password: e.target.value })}
                              placeholder="Vacía = le enviamos un mail para que elija la suya"
                              className={field}
                            />
                          </div>
                        </>
                      );
                    })()}
                    {createMessage && <FormMessage {...createMessage} />}
                    <div className="flex justify-end gap-2 pt-2">
                      <button
                        type="button"
                        onClick={() => setCreateOpen(false)}
                        disabled={isCreating}
                        className="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-dark-bg"
                      >
                        Cancelar
                      </button>
                      <button
                        type="submit"
                        disabled={isCreating}
                        className="px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold disabled:opacity-60"
                      >
                        {isCreating ? "Creando..." : "Crear cliente"}
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            )}

            {/* --- CATEGORIES VIEW --- */}
            {currentView === "categories" && (
              <div className="space-y-6 max-w-5xl">
                <h2 className="text-2xl font-bold dark:text-white">
                  Categorías y Subcategorías
                </h2>
                {categoryMessage && <FormMessage {...categoryMessage} />}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <form
                    className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border space-y-3"
                    onSubmit={(e) => {
                      e.preventDefault();
                      saveCategory(
                        {
                          name: newCategory.name.trim(),
                          image_url: newCategory.image_url.trim() || null,
                        },
                        `Categoría «${newCategory.name.trim()}» creada.`,
                        () => setNewCategory({ name: "", image_url: "" }),
                      );
                    }}
                  >
                    <h3 className="font-bold dark:text-white">Nueva categoría</h3>
                    <input
                      required
                      maxLength={100}
                      placeholder="Nombre (ej: Cerramientos)"
                      value={newCategory.name}
                      onChange={(e) => setNewCategory({ ...newCategory, name: e.target.value })}
                      className="w-full p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm"
                    />
                    <input
                      type="url"
                      placeholder="Link de una imagen (opcional)"
                      value={newCategory.image_url}
                      onChange={(e) => setNewCategory({ ...newCategory, image_url: e.target.value })}
                      className="w-full p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm"
                    />
                    <button
                      type="submit"
                      disabled={isSavingCategory}
                      className="px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold disabled:opacity-60 flex items-center gap-2"
                    >
                      <Plus size={16} /> Crear categoría
                    </button>
                  </form>

                  <form
                    className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border space-y-3"
                    onSubmit={(e) => {
                      e.preventDefault();
                      if (newSub.parent_id === "") return;
                      saveCategory(
                        { name: newSub.name.trim(), parent_id: Number(newSub.parent_id) },
                        `Subcategoría «${newSub.name.trim()}» creada.`,
                        () => setNewSub({ ...newSub, name: "" }),
                      );
                    }}
                  >
                    <h3 className="font-bold dark:text-white">Nueva subcategoría</h3>
                    <select
                      required
                      value={newSub.parent_id}
                      onChange={(e) =>
                        setNewSub({
                          ...newSub,
                          parent_id: e.target.value ? Number(e.target.value) : "",
                        })
                      }
                      className="w-full p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm"
                    >
                      <option value="">Elegí la categoría</option>
                      {categories.map((c) => (
                        <option key={c.id} value={c.id}>
                          {c.name}
                        </option>
                      ))}
                    </select>
                    <input
                      required
                      maxLength={100}
                      placeholder="Nombre (ej: Portones)"
                      value={newSub.name}
                      onChange={(e) => setNewSub({ ...newSub, name: e.target.value })}
                      className="w-full p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm"
                    />
                    <button
                      type="submit"
                      disabled={isSavingCategory}
                      className="px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold disabled:opacity-60 flex items-center gap-2"
                    >
                      <Plus size={16} /> Crear subcategoría
                    </button>
                  </form>
                </div>

                <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border divide-y divide-slate-100 dark:divide-dark-border">
                  {categories.map((c) => (
                    <div key={c.id} className="p-5">
                      <div className="font-bold dark:text-white">{c.name}</div>
                      <div className="flex flex-wrap gap-2 mt-2">
                        {c.subcategories.map((s) => (
                          <span
                            key={s.id}
                            className="text-xs font-bold px-2.5 py-1 rounded-full bg-slate-100 dark:bg-dark-bg text-slate-600 dark:text-slate-300"
                          >
                            {s.name}
                          </span>
                        ))}
                        {c.subcategories.length === 0 && (
                          <span className="text-xs text-slate-400">Sin subcategorías</span>
                        )}
                      </div>
                    </div>
                  ))}
                  {categories.length === 0 && (
                    <p className="p-5 text-sm text-slate-400">Todavía no hay categorías.</p>
                  )}
                </div>
                <p className="text-xs text-slate-400">
                  Las categorías nuevas aparecen enseguida en el menú del sitio y en el editor de detalles.
                </p>
              </div>
            )}

            {/* --- STAFF VIEW --- */}
            {currentView === "staff" && (
              <div className="space-y-6">
                <div className="flex flex-wrap justify-between items-center gap-3">
                  <h2 className="text-2xl font-bold dark:text-white">
                    Miembros del Staff
                  </h2>
                  <button
                    onClick={() => {
                      setStaffMessage(null);
                      setMessage(null);
                      setStaffTab("new");
                      setStaffOpen(true);
                    }}
                    className="flex items-center gap-2 px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold"
                  >
                    <UserPlus size={16} /> Agregar al staff
                  </button>
                </div>
                {message && <FormMessage {...message} />}
                <p className="text-sm text-slate-500 dark:text-slate-400 max-w-2xl">
                  Los colaboradores pueden subir detalles y usar la mensajería. También podés convertir a un
                  cliente en colaborador desde su ficha en <strong>Clientes</strong>.
                </p>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                  {users.map((author) => {
                    const isAdminUser = author.roles.includes("superadmin");
                    return (
                      <div
                        key={author.id}
                        onClick={isAdminUser ? undefined : () => handleOpenUserInspector(author)}
                        className={`bg-white dark:bg-dark-card p-6 rounded-2xl border flex items-center gap-4 transition-all ${
                          isAdminUser
                            ? "border-primary-400 ring-1 ring-primary-400/40"
                            : "border-slate-200 dark:border-dark-border cursor-pointer hover:shadow-lg"
                        }`}
                      >
                        <img
                          src={author.avatar_url || DEFAULT_AVATAR}
                          className="w-16 h-16 rounded-full object-cover"
                        />
                        <div className="min-w-0">
                          <h3 className="font-bold text-lg dark:text-white truncate">
                            {author.name}
                            {author.status === "suspended" && (
                              <span className="ml-2 text-[10px] font-bold px-1.5 py-0.5 rounded bg-red-100 text-red-700 align-middle">
                                SUSPENDIDO
                              </span>
                            )}
                          </h3>
                          <span
                            className={`inline-block text-[10px] font-bold px-2 py-0.5 rounded-full mb-1 ${
                              isAdminUser
                                ? "bg-primary-400 text-slate-900"
                                : "bg-slate-100 dark:bg-dark-bg text-slate-600 dark:text-slate-300"
                            }`}
                          >
                            {isAdminUser ? "ADMINISTRADOR" : "COLABORADOR"}
                          </span>
                          <p className="text-sm text-primary-500 font-medium truncate">
                            {author.profession}
                          </p>
                          <p className="text-xs text-slate-400 truncate">{author.company || author.email}</p>
                        </div>
                      </div>
                    );
                  })}
                  {users.length === 0 && (
                    <p className="text-sm text-slate-400">Cargando...</p>
                  )}
                </div>
              </div>
            )}

            {/* --- Agregar al staff (modal) --- */}
            {currentView === "staff" && staffOpen && (
              <div
                className="fixed inset-0 z-30 bg-black/40 flex items-center justify-center p-4"
                onClick={closeStaffModal}
              >
                <div
                  className="w-full max-w-md bg-white dark:bg-dark-surface rounded-2xl shadow-2xl border border-slate-200 dark:border-dark-border p-6 space-y-4 max-h-[90vh] overflow-y-auto"
                  onClick={(e) => e.stopPropagation()}
                >
                  <div className="flex justify-between items-start">
                    <h3 className="font-bold text-xl dark:text-white">Agregar al staff</h3>
                    <button onClick={closeStaffModal} disabled={isSavingStaff} className="p-1 hover:bg-slate-100 rounded-full">
                      <X size={20} />
                    </button>
                  </div>
                  <div className="flex gap-1 bg-slate-100 dark:bg-dark-bg p-1 rounded-xl">
                    {(
                      [
                        ["new", "Crear usuario nuevo"],
                        ["existing", "Convertir un cliente"],
                      ] as const
                    ).map(([tab, label]) => (
                      <button
                        key={tab}
                        type="button"
                        onClick={() => {
                          setStaffTab(tab);
                          setStaffMessage(null);
                        }}
                        className={`flex-1 py-2 rounded-lg text-sm font-bold transition-all ${
                          staffTab === tab
                            ? "bg-white dark:bg-dark-card shadow text-slate-900 dark:text-white"
                            : "text-slate-500"
                        }`}
                      >
                        {label}
                      </button>
                    ))}
                  </div>

                  {(() => {
                    const field =
                      "w-full p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm";
                    const label = "block text-xs font-bold text-slate-500 uppercase mb-1";
                    return staffTab === "new" ? (
                      <form
                        className="space-y-3"
                        onSubmit={(e) => {
                          e.preventDefault();
                          handleCreateStaff();
                        }}
                      >
                        <div>
                          <label className={label}>Nombre</label>
                          <input required value={staffForm.name} onChange={(e) => setStaffForm({ ...staffForm, name: e.target.value })} className={field} />
                        </div>
                        <div>
                          <label className={label}>Email</label>
                          <input required type="email" value={staffForm.email} onChange={(e) => setStaffForm({ ...staffForm, email: e.target.value })} className={field} />
                        </div>
                        <div>
                          <label className={label}>Contraseña (opcional)</label>
                          <input
                            type="text"
                            autoComplete="off"
                            minLength={8}
                            value={staffForm.password}
                            onChange={(e) => setStaffForm({ ...staffForm, password: e.target.value })}
                            placeholder="Vacía = le enviamos un mail para que elija la suya"
                            className={field}
                          />
                        </div>
                        {staffMessage && <FormMessage {...staffMessage} />}
                        <div className="flex justify-end gap-2 pt-2">
                          <button type="button" onClick={closeStaffModal} disabled={isSavingStaff} className="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-dark-bg">
                            Cancelar
                          </button>
                          <button type="submit" disabled={isSavingStaff} className="px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold disabled:opacity-60">
                            {isSavingStaff ? "Creando..." : "Crear colaborador"}
                          </button>
                        </div>
                      </form>
                    ) : (
                      <div className="space-y-3">
                        <form
                          className="flex gap-2"
                          onSubmit={(e) => {
                            e.preventDefault();
                            searchClients();
                          }}
                        >
                          <input
                            value={clientQuery}
                            onChange={(e) => setClientQuery(e.target.value)}
                            placeholder="Buscar cliente por nombre o email"
                            className={field}
                          />
                          <button type="submit" className="px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold">
                            Buscar
                          </button>
                        </form>
                        {clientResults !== null && clientResults.length === 0 && (
                          <p className="text-sm text-slate-400 text-center py-4">No se encontraron clientes.</p>
                        )}
                        <div className="space-y-2">
                          {(clientResults ?? []).slice(0, 20).map((u) => (
                            <div key={u.id} className="flex items-center gap-3 p-3 rounded-xl border border-slate-200 dark:border-dark-border">
                              <img src={u.avatar_url || DEFAULT_AVATAR} className="w-10 h-10 rounded-full object-cover" />
                              <div className="flex-1 min-w-0">
                                <p className="font-bold text-sm dark:text-white truncate">{u.name}</p>
                                <p className="text-xs text-slate-400 truncate">{u.email}</p>
                              </div>
                              <button
                                disabled={isSavingStaff}
                                onClick={() => promoteToStaff(u)}
                                className="px-3 py-1.5 bg-primary-400 hover:bg-primary-500 text-slate-900 rounded-lg text-xs font-bold disabled:opacity-60"
                              >
                                Hacer colaborador
                              </button>
                            </div>
                          ))}
                        </div>
                        {staffMessage && <FormMessage {...staffMessage} />}
                        <div className="flex justify-end pt-2">
                          <button type="button" onClick={closeStaffModal} className="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-dark-bg">
                            Cerrar
                          </button>
                        </div>
                      </div>
                    );
                  })()}
                </div>
              </div>
            )}

            {/* --- REPORTS VIEW --- */}
            {currentView === "reports" && (
              <div className="space-y-6">
                <h2 className="text-2xl font-bold dark:text-white">
                  Reportes de Errores
                </h2>
                <div className="grid grid-cols-1 gap-4">
                  {(reports || []).map((report) => {
                    const daysPending = Math.floor(
                      (new Date().getTime() -
                        new Date(report.submittedAt).getTime()) /
                        (1000 * 3600 * 24),
                    );
                    return (
                      <div
                        key={report.id}
                        className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border shadow-sm flex flex-col md:flex-row justify-between items-start gap-4"
                      >
                        <div className="flex-1">
                          <div className="flex items-center gap-3 mb-2">
                            <span
                              className={`px-2 py-0.5 rounded text-xs font-bold ${report.status === "Pending" ? "bg-red-100 text-red-700 animate-pulse" : "bg-green-100 text-green-700"}`}
                            >
                              {report.status === "Pending" ? "Pendiente" : "Resuelto"}
                            </span>
                            <span className="text-xs text-slate-400 font-medium flex items-center gap-1">
                              <Calendar size={12} /> {report.submittedAt}
                            </span>
                            {report.status === "Pending" && (
                              <span className="text-xs text-red-400 font-bold">
                                ({daysPending} días en espera)
                              </span>
                            )}
                          </div>
                          <h3 className="font-bold text-lg dark:text-white mb-1">
                            {report.detailTitle}{" "}
                            <span className="text-sm font-normal text-slate-400">
                              (ID: {report.detailId})
                            </span>
                          </h3>
                          <p className="text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-dark-bg p-3 rounded-lg border border-slate-100 dark:border-dark-border/50 text-sm italic">
                            "{report.description}"
                          </p>
                        </div>
                        <div className="flex flex-col gap-2 w-full md:w-auto">
                          <button
                            onClick={() => {
                              if (report.detailId) handleEditDetail(Number(report.detailId));
                              else alert("Detalle no encontrado (pudo ser borrado)");
                            }}
                            className="px-4 py-2 bg-primary-400 text-slate-900 rounded-lg text-sm font-bold flex items-center justify-center gap-2 hover:bg-primary-500"
                          >
                            <Edit3 size={16} /> Revisar y Editar Detalle
                          </button>
                          {report.status === "Pending" && (
                            <button
                              onClick={() => onResolveReport(report.id)}
                              className="px-4 py-2 bg-green-500 text-white rounded-lg text-sm font-bold flex items-center justify-center gap-2 hover:bg-green-600"
                            >
                              <CheckSquare size={16} /> Marcar Resuelto
                            </button>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {/* --- PAGOS Y REEMBOLSOS --- */}
            {currentView === "orders" && <AdminOrders />}
            {currentView === "refunds" && <AdminRefunds onPendingCount={setPendingRefunds} />}

            {/* --- COMMERCE VIEW --- */}
            {currentView === "commerce" && (
              <div className="space-y-8">
                <div className="flex justify-between items-center">
                  <h2 className="text-2xl font-bold dark:text-white">
                    Planes y Precios
                  </h2>
                  <div className="flex gap-3">
                    <button
                      onClick={handleSavePlans}
                      disabled={isSaving}
                      className="bg-primary-400 text-slate-900 px-6 py-2 rounded-xl font-bold flex items-center gap-2 text-sm disabled:opacity-50"
                    >
                      <Save size={18} /> {isSaving ? "Guardando..." : "Guardar Cambios"}
                    </button>
                  </div>
                </div>
                {message && <FormMessage {...message} />}
                <div className="bg-white dark:bg-dark-card p-5 rounded-2xl border border-slate-200 dark:border-dark-border space-y-3">
                  <p className="text-sm text-slate-500 dark:text-slate-400">
                    Los precios de abajo son mensuales. Los pagos por 3 meses y por año se calculan solos con estos descuentos.
                  </p>
                  <div className="flex flex-wrap gap-4">
                    <label className="block">
                      <span className="block text-xs font-bold text-slate-500 uppercase mb-1">
                        Descuento 3 meses (%)
                      </span>
                      <input
                        type="number"
                        min={0}
                        max={90}
                        step={1}
                        value={discounts.quarterly}
                        onChange={(e) => setDiscounts({ ...discounts, quarterly: e.target.value })}
                        className="w-32 p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm"
                      />
                    </label>
                    <label className="block">
                      <span className="block text-xs font-bold text-slate-500 uppercase mb-1">
                        Descuento anual (%)
                      </span>
                      <input
                        type="number"
                        min={0}
                        max={90}
                        step={1}
                        value={discounts.yearly}
                        onChange={(e) => setDiscounts({ ...discounts, yearly: e.target.value })}
                        className="w-32 p-2.5 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm"
                      />
                    </label>
                  </div>
                  <p className="text-xs text-slate-400">
                    Se guardan con el botón «Guardar Cambios». Las compras ya hechas no cambian.
                  </p>
                </div>
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                  {plansForm.map((plan, idx) => (
                    <div
                      key={plan.id}
                      className="bg-white dark:bg-dark-card p-6 rounded-2xl border border-slate-200 dark:border-dark-border relative group"
                    >
                      <div className="flex justify-between mb-4">
                        <input
                          value={plan.name}
                          onChange={(e) =>
                            handleUpdatePlan(idx, "name", e.target.value)
                          }
                          className="font-bold text-lg bg-transparent border-b border-dashed border-slate-300 focus:border-primary-400 outline-none dark:text-white w-full mr-2"
                        />
                      </div>
                      {/* El plan Free no se cobra */}
                      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 mb-4">
                        <label className="flex items-center dark:text-white">
                          ARS $
                          <input
                            type="number"
                            min={0}
                            disabled={plan.slug === "free"}
                            value={plan.priceArs ?? 0}
                            onChange={(e) =>
                              handleUpdatePlan(idx, "priceArs", Number(e.target.value))
                            }
                            className="w-28 font-bold text-2xl bg-transparent border-b border-dashed border-slate-300 focus:border-primary-400 outline-none dark:text-white ml-2 disabled:opacity-50"
                          />
                        </label>
                        <label className="flex items-center dark:text-white">
                          USD $
                          <input
                            type="number"
                            min={0}
                            step="0.01"
                            disabled={plan.slug === "free"}
                            value={plan.priceUsd ?? 0}
                            onChange={(e) =>
                              handleUpdatePlan(idx, "priceUsd", Number(e.target.value))
                            }
                            className="w-20 font-bold text-2xl bg-transparent border-b border-dashed border-slate-300 focus:border-primary-400 outline-none dark:text-white ml-2 disabled:opacity-50"
                          />
                        </label>
                        <span className="text-slate-400">/ mes</span>
                      </div>
                      <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 mb-4 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={!!plan.isRecommended}
                          onChange={(e) =>
                            setPlansForm(
                              plansForm.map((p, i) => ({
                                ...p,
                                // Un solo plan recomendado
                                isRecommended: i === idx ? e.target.checked : e.target.checked ? false : p.isRecommended,
                              })),
                            )
                          }
                          className="w-4 h-4 accent-primary-400"
                        />
                        Destacar como recomendado
                      </label>
                      <textarea
                        value={plan.description}
                        onChange={(e) =>
                          handleUpdatePlan(idx, "description", e.target.value)
                        }
                        className="w-full bg-slate-50 dark:bg-dark-bg p-3 rounded-lg text-sm resize-none mb-4 dark:text-white"
                        rows={2}
                      />

                      <div className="bg-slate-50 dark:bg-dark-bg p-3 rounded-lg">
                        <label className="text-xs font-bold text-slate-400 uppercase mb-2 block">
                          Características (una por línea)
                        </label>
                        <textarea
                          value={plan.features.join("\n")}
                          onChange={(e) =>
                            handleUpdatePlan(
                              idx,
                              "features",
                              e.target.value.split("\n"),
                            )
                          }
                          className="w-full bg-transparent text-sm resize-none outline-none dark:text-white"
                          rows={5}
                        />
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* --- COMMENTS VIEW --- */}
            {currentView === "comments" && (
              <div className="space-y-6 max-w-6xl">
                <div className="flex flex-wrap justify-between items-center gap-3">
                  <h2 className="text-2xl font-bold dark:text-white">
                    Comentarios{" "}
                    {commentsPage && (
                      <span className="text-sm font-normal text-slate-400">({commentsPage.total})</span>
                    )}
                  </h2>
                  <form
                    className="flex gap-2"
                    onSubmit={(e) => {
                      e.preventDefault();
                      setCommentSearch(commentQuery.trim());
                      setCommentPageNo(1);
                      setCommentsPage(null);
                      loadComments(commentQuery.trim(), 1);
                    }}
                  >
                    <input
                      value={commentQuery}
                      onChange={(e) => setCommentQuery(e.target.value)}
                      placeholder="Buscar por texto, persona o detalle"
                      className="w-72 max-w-full p-2.5 bg-white dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm"
                    />
                    <button type="submit" className="px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold">
                      Buscar
                    </button>
                  </form>
                </div>
                {commentsMessage && <FormMessage {...commentsMessage} />}
                <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border divide-y divide-slate-100 dark:divide-dark-border">
                  {commentsPage === null && <p className="p-6 text-sm text-slate-400">Cargando...</p>}
                  {commentsPage?.comments.length === 0 && (
                    <p className="p-6 text-sm text-slate-400 text-center">No hay comentarios.</p>
                  )}
                  {commentsPage?.comments.map((c) => (
                    <div key={c.id} className="p-5 flex gap-4">
                      <img
                        src={c.user?.avatar_url || DEFAULT_AVATAR}
                        className="w-10 h-10 rounded-full object-cover shrink-0"
                      />
                      <div className="flex-1 min-w-0">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                          <span className="font-bold dark:text-white">{c.user?.name ?? "Usuario eliminado"}</span>
                          {c.user && <span className="text-xs text-slate-400">{c.user.email}</span>}
                          <span className="text-xs text-slate-500 ml-auto whitespace-nowrap">
                            {fullDate(c.created_at)}
                            {c.edited && <span className="italic"> · editado</span>}
                          </span>
                        </div>
                        <p className="text-xs text-slate-400 mt-0.5">
                          {c.is_reply ? `Respuesta${c.reply_to ? ` a ${c.reply_to}` : ""} en ` : "Comentó en "}
                          {c.detail ? (
                            <button
                              type="button"
                              onClick={() => c.detail && handleEditDetail(c.detail.id)}
                              className="font-bold text-primary-600 dark:text-primary-400 hover:underline"
                            >
                              {c.detail.title}
                            </button>
                          ) : (
                            "un detalle eliminado"
                          )}
                        </p>
                        {editingComment?.id === c.id ? (
                          <div className="mt-2">
                            <textarea
                              autoFocus
                              maxLength={2000}
                              value={editingComment.text}
                              onChange={(e) => setEditingComment({ id: c.id, text: e.target.value })}
                              className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-lg dark:text-white text-sm min-h-[80px] resize-y"
                            />
                            <div className="flex gap-2 justify-end mt-2">
                              <button onClick={() => setEditingComment(null)} className="px-3 py-1.5 rounded-lg text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-dark-bg">
                                Cancelar
                              </button>
                              <button
                                onClick={saveCommentEdit}
                                disabled={isSavingComment || !editingComment.text.trim()}
                                className="px-3 py-1.5 bg-primary-400 hover:bg-primary-500 text-slate-900 rounded-lg text-sm font-bold disabled:opacity-60"
                              >
                                Guardar
                              </button>
                            </div>
                          </div>
                        ) : (
                          <>
                            <p className="mt-2 text-sm text-slate-700 dark:text-slate-200 whitespace-pre-line break-words">{c.body}</p>
                            <div className="flex gap-4 mt-2">
                              <button onClick={() => setEditingComment({ id: c.id, text: c.body })} className="text-xs font-bold text-slate-400 hover:text-primary-500 flex items-center gap-1">
                                <Edit3 size={12} /> Editar
                              </button>
                              <button onClick={() => deleteComment(c.id, !c.is_reply)} className="text-xs font-bold text-slate-400 hover:text-red-500 flex items-center gap-1">
                                <Trash2 size={12} /> Eliminar
                              </button>
                            </div>
                          </>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
                {commentsPage && commentsPage.last_page > 1 && (
                  <div className="flex items-center justify-center gap-3">
                    <button
                      disabled={commentPageNo <= 1}
                      onClick={() => { const p = commentPageNo - 1; setCommentPageNo(p); loadComments(commentSearch, p); }}
                      className="px-4 py-2 rounded-lg text-sm font-bold border border-slate-200 dark:border-dark-border dark:text-white disabled:opacity-40"
                    >
                      Anterior
                    </button>
                    <span className="text-sm text-slate-500">
                      Página {commentsPage.current_page} de {commentsPage.last_page}
                    </span>
                    <button
                      disabled={commentPageNo >= commentsPage.last_page}
                      onClick={() => { const p = commentPageNo + 1; setCommentPageNo(p); loadComments(commentSearch, p); }}
                      className="px-4 py-2 rounded-lg text-sm font-bold border border-slate-200 dark:border-dark-border dark:text-white disabled:opacity-40"
                    >
                      Siguiente
                    </button>
                  </div>
                )}
              </div>
            )}

            {/* --- BACKUPS VIEW --- */}
            {currentView === "backups" && (
              <div className="space-y-6 max-w-4xl">
                <div className="flex flex-wrap justify-between items-center gap-3">
                  <h2 className="text-2xl font-bold dark:text-white">Backups</h2>
                  <button
                    onClick={createBackup}
                    disabled={isBackingUp}
                    className="flex items-center gap-2 px-4 py-2 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-lg text-sm font-bold disabled:opacity-60"
                  >
                    <HardDrive size={16} /> {isBackingUp ? "Creando backup..." : "Crear backup ahora"}
                  </button>
                </div>
                {isBackingUp && (
                  <p className="text-sm text-slate-500">Esto puede tardar un minuto. No cierres esta pantalla.</p>
                )}
                {backupMessage && <FormMessage {...backupMessage} />}
                <div className="bg-white dark:bg-dark-card p-5 rounded-2xl border border-slate-200 dark:border-dark-border text-sm text-slate-600 dark:text-slate-300 space-y-1.5">
                  <p>
                    <strong>Automático:</strong> todos los días a las {backupData?.auto_time ?? "03:30"} (hora del servidor). Se conservan los últimos {backupData?.keep_auto ?? 7}.
                  </p>
                  <p>
                    <strong>Manual:</strong> los que creás con el botón. Se conservan los últimos {backupData?.keep_manual ?? 10}.
                  </p>
                  <p>
                    Cada backup es un .zip con la <strong>base de datos</strong> (database.sql, se restaura desde phpMyAdmin → Importar) y los
                    <strong> archivos del sitio</strong> (código, archivos subidos y comprobantes). No incluye el archivo .env con las claves: guardá una copia aparte.
                  </p>
                  <p className="text-amber-700 dark:text-amber-400">
                    Los backups se guardan en el mismo hosting. Descargá los importantes y guardalos en tu computadora o en la nube.
                  </p>
                </div>
                <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border overflow-hidden">
                  <table className="w-full text-left">
                    <thead className="bg-slate-50 dark:bg-dark-bg border-b border-slate-200 dark:border-dark-border">
                      <tr>
                        <th className="p-4 text-xs font-bold text-slate-500 uppercase">Fecha</th>
                        <th className="p-4 text-xs font-bold text-slate-500 uppercase">Tipo</th>
                        <th className="p-4 text-xs font-bold text-slate-500 uppercase">Tamaño</th>
                        <th className="p-4" />
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-dark-border">
                      {backupData === null && (
                        <tr><td colSpan={4} className="p-6 text-sm text-slate-400">Cargando...</td></tr>
                      )}
                      {backupData?.backups.length === 0 && (
                        <tr><td colSpan={4} className="p-6 text-sm text-slate-400 text-center">Todavía no hay backups.</td></tr>
                      )}
                      {backupData?.backups.map((b) => (
                        <tr key={b.name}>
                          <td className="p-4 text-sm dark:text-white">{fullDate(b.created_at)}</td>
                          <td className="p-4">
                            <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${b.type === "auto" ? "bg-blue-100 text-blue-700" : "bg-slate-100 text-slate-700"}`}>
                              {b.type === "auto" ? "AUTOMÁTICO" : "MANUAL"}
                            </span>
                          </td>
                          <td className="p-4 text-sm text-slate-500">{formatSize(b.size)}</td>
                          <td className="p-4">
                            <div className="flex justify-end gap-3">
                              <a
                                href={AdminApi.backupUrl(b.name)}
                                download={b.name}
                                className="flex items-center gap-1 text-xs font-bold text-primary-600 dark:text-primary-400 hover:underline"
                              >
                                <Download size={14} /> Descargar
                              </a>
                              <button onClick={() => deleteBackup(b.name)} className="flex items-center gap-1 text-xs font-bold text-slate-400 hover:text-red-500">
                                <Trash2 size={14} /> Eliminar
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* --- SETTINGS VIEW --- */}
            {currentView === "settings" && (
              <div className="max-w-4xl mx-auto pb-20">
                <div className="flex justify-between items-center mb-8">
                  <h2 className="text-3xl font-bold dark:text-white">
                    Configuración del Sitio
                  </h2>
                  <button
                    onClick={handleSaveSettings}
                    disabled={isSaving}
                    className="bg-primary-400 text-slate-900 px-8 py-3 rounded-xl font-bold flex items-center gap-2 hover:bg-primary-500 shadow-lg shadow-primary-400/20 disabled:opacity-50"
                  >
                    <Save size={20} /> {isSaving ? "Guardando..." : "Guardar Todo"}
                  </button>
                </div>

                {message && (
                  <div className="-mt-4 mb-6">
                    <FormMessage {...message} />
                  </div>
                )}

                <div className="space-y-8">
                  {/* Modo mantenimiento: el sitio queda cerrado salvo para el superadmin */}
                  <label className="flex items-center justify-between gap-4 bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border p-6 cursor-pointer">
                    <div>
                      <span className="block text-lg font-bold dark:text-white">Modo mantenimiento</span>
                      <span className="text-sm text-slate-500 dark:text-slate-400">
                        Con el modo activo, solo vos podés usar el sitio. Los demás ven un aviso.
                      </span>
                    </div>
                    <input
                      type="checkbox"
                      checked={!!configForm.maintenanceMode}
                      onChange={(e) =>
                        setConfigForm({ ...configForm, maintenanceMode: e.target.checked })
                      }
                      className="w-5 h-5 accent-primary-400 shrink-0"
                    />
                  </label>
                  {/* Visuals */}
                  <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border p-8">
                    <h3 className="text-lg font-bold dark:text-white mb-6 border-b border-slate-100 dark:border-dark-border pb-2">
                      Identidad Visual
                    </h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                      <div>
                        <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                          Título Principal (Hero)
                        </label>
                        <input
                          value={configForm.homeTitle || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              homeTitle: e.target.value,
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                          Subtítulo
                        </label>
                        <input
                          value={configForm.homeSubtitle || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              homeSubtitle: e.target.value,
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white"
                        />
                      </div>
                      <div className="md:col-span-2">
                        <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                          Imagen de Cabecera (URL)
                        </label>
                        <input
                          value={configForm.headerImageUrl || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              headerImageUrl: e.target.value,
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white font-mono text-xs"
                        />
                      </div>
                    </div>
                  </div>

                  {/* Paywall Texts */}
                  <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border p-8">
                    <h3 className="text-lg font-bold dark:text-white mb-6 border-b border-slate-100 dark:border-dark-border pb-2">
                      Textos Paywall (Pro)
                    </h3>
                    <div className="space-y-4">
                      <div>
                        <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                          Título del Modal
                        </label>
                        <input
                          value={configForm.paywallTitle || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              paywallTitle: e.target.value,
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white"
                          placeholder="Desbloquea este Detalle Constructivo"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                          Descripción
                        </label>
                        <textarea
                          rows={2}
                          value={configForm.paywallDescription || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              paywallDescription: e.target.value,
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white resize-none"
                          placeholder="Accede a planimetría..."
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                          Texto del Botón
                        </label>
                        <input
                          value={configForm.paywallButtonText || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              paywallButtonText: e.target.value,
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white"
                          placeholder="Obtener Acceso Pro"
                        />
                      </div>
                    </div>
                  </div>

                  {/* Legal & Social */}
                  <div className="bg-white dark:bg-dark-card rounded-2xl border border-slate-200 dark:border-dark-border p-8">
                    <h3 className="text-lg font-bold dark:text-white mb-6 border-b border-slate-100 dark:border-dark-border pb-2">
                      Legal y Contacto
                    </h3>
                    <div className="space-y-4">
                      <div>
                        <label className="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                          Texto Footer
                        </label>
                        <input
                          value={configForm.footerText || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              footerText: e.target.value,
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white"
                        />
                      </div>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input
                          placeholder="Twitter URL"
                          value={configForm.socialLinks?.twitter || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              socialLinks: {
                                ...configForm.socialLinks,
                                twitter: e.target.value,
                              },
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white"
                        />
                        <input
                          placeholder="Instagram URL"
                          value={configForm.socialLinks?.instagram || ""}
                          onChange={(e) =>
                            setConfigForm({
                              ...configForm,
                              socialLinks: {
                                ...configForm.socialLinks,
                                instagram: e.target.value,
                              },
                            })
                          }
                          className="w-full p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl dark:text-white"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
      {/* SLIDE OVER */}
      {renderSlideOver()}
    </div>
  );
};

export default AdminPanel;