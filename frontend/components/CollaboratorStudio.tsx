import React, { useEffect, useState } from "react";
import { FileText, Plus } from "lucide-react";
import {
  ApiCategory,
  SiteApi,
  StudioApi,
  StudioDetailSummary,
  timeAgo,
} from "../services/api";
import DetailEditor, { StatusBadge } from "./DetailEditor";

const cardClass =
  "bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm";
const primaryButtonClass =
  "px-6 py-3 bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold rounded-xl shadow-lg disabled:opacity-50 disabled:cursor-not-allowed";

interface CollaboratorStudioProps {
  onOpenPublished?: (slug: string) => void;
}

// Pestaña "Mis detalles" de los colaboradores: cargar, guardar borradores y enviar a revisión
const CollaboratorStudio: React.FC<CollaboratorStudioProps> = ({ onOpenPublished }) => {
  const [list, setList] = useState<StudioDetailSummary[] | null>(null);
  const [categories, setCategories] = useState<ApiCategory[]>([]);
  const [mode, setMode] = useState<"list" | "edit">("list");
  const [editingId, setEditingId] = useState<number | null>(null);

  const loadList = () =>
    StudioApi.list()
      .then(setList)
      .catch(() => setList([]));

  useEffect(() => {
    loadList();
    SiteApi.categories().then(setCategories).catch(console.error);
  }, []);

  const openEditor = (id: number | null) => {
    setEditingId(id);
    setMode("edit");
  };

  const backToList = () => {
    setMode("list");
    setEditingId(null);
    loadList();
  };

  // ---------- Listado ----------
  if (mode === "list") {
    return (
      <div className={cardClass}>
        <div className="flex flex-wrap justify-between items-start gap-4 mb-6">
          <div>
            <h2 className="text-2xl font-bold dark:text-white">Mis detalles</h2>
            <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
              Cargá tus detalles y envialos a revisión. Se publican cuando el
              equipo de enDetalle los aprueba.
            </p>
          </div>
          <button onClick={() => openEditor(null)} className={`${primaryButtonClass} flex items-center gap-2`}>
            <Plus size={18} /> Subir detalle
          </button>
        </div>

        {list === null ? (
          <p className="text-sm text-slate-400">Cargando...</p>
        ) : list.length === 0 ? (
          <p className="text-sm text-slate-500 dark:text-slate-400 italic py-8 text-center">
            Todavía no cargaste ningún detalle.
          </p>
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-dark-border">
            {list.map((d) => (
              <li key={d.id} className="flex items-center gap-4 py-3">
                <div className="w-14 h-[4.5rem] rounded-lg overflow-hidden bg-slate-100 dark:bg-dark-bg shrink-0 flex items-center justify-center text-slate-300">
                  {d.cover_url ? (
                    <img src={d.cover_url} alt="" className="w-full h-full object-cover" />
                  ) : (
                    <FileText size={20} />
                  )}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-bold text-slate-900 dark:text-white truncate">{d.title}</p>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    {d.category ?? "Sin categoría"} · actualizado {timeAgo(d.updated_at).toLowerCase()}
                  </p>
                </div>
                <StatusBadge status={d.status} />
                {d.status === "published" && (
                  <button
                    onClick={() => onOpenPublished?.(d.slug)}
                    className="text-sm font-bold text-slate-500 dark:text-slate-400 hover:underline"
                  >
                    Ver
                  </button>
                )}
                {d.status !== "archived" && (
                  <button
                    onClick={() => openEditor(d.id)}
                    className="text-sm font-bold text-primary-600 dark:text-primary-400 hover:underline"
                  >
                    Editar
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    );
  }

  // ---------- Editor ----------
  return (
    <DetailEditor
      key={editingId ?? "new"}
      detailId={editingId}
      categories={categories}
      backLabel="Mis detalles"
      onBack={backToList}
      onChanged={loadList}
    />
  );
};

export default CollaboratorStudio;
