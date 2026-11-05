
import React, { useState, useEffect, useMemo, useRef } from 'react';
import { ConstructionDetail, Author, Comment, ClientUser } from '../types';
import { absoluteUrl, detailPath } from '../paths';
import { errorText, downloadDetailFile, fullDate } from '../services/api';
import { ArrowLeft, CheckCircle2, Download, Share2, Maximize2, Box, Layers, ArrowRight, ChevronLeft, ChevronRight, AlertTriangle, Heart, Send, MessageSquare, ImageOff, BoxSelect, Bookmark, MessageCircle, Instagram, Link2, Lock, Crown, X } from 'lucide-react';

interface DetailViewProps {
  detail: ConstructionDetail;
  comments?: Comment[];
  onAddComment?: (detailId: string, text: string, parentId?: string) => void | Promise<void>;
  onDeleteComment?: (commentId: string) => Promise<void>;
  onEditComment?: (commentId: string, text: string) => Promise<void>;
  onCommentAuthorClick?: (author: Author) => void;
  onToggleVote?: () => Promise<void>;
  onBack: () => void;
  relatedDetails?: ConstructionDetail[];
  onAuthorClick: (author: Author) => void;
  onReportError: (id: string, description: string) => void | Promise<void>;
  onSelectDetail?: (detail: ConstructionDetail) => void;
  currentUser?: ClientUser | null;
  isFavorite?: boolean;
  onToggleFavorite?: (detailId: string) => void;
  onViewPlans?: () => void;
}

// Error de un campo, arriba del input
const FieldError: React.FC<{ message: string }> = ({ message }) => (
  <p role="alert" className="text-sm font-semibold text-red-500 mb-2 flex items-center gap-1.5">
    <AlertTriangle size={14} className="shrink-0" /> {message}
  </p>
);

// Los errores de validación y permisos vienen de la API ya en español
const errorMessage = errorText;

const PLACEHOLDER_IMG = "https://placehold.co/600x800/e2e8f0/64748b?text=Imagen+No+Disponible";

const DetailView: React.FC<DetailViewProps> = ({ 
  detail, 
  comments = [],
  onAddComment,
  onDeleteComment,
  onEditComment,
  onCommentAuthorClick,
  onToggleVote,
  onBack,
  relatedDetails = [], 
  onAuthorClick, 
  onReportError, 
  onSelectDetail, 
  currentUser,
  isFavorite: externalIsFavorite,
  onToggleFavorite,
  onViewPlans
}) => {
  const [selectedImage, setSelectedImage] = useState<string>(detail.imageUrl);
  const [imageError, setImageError] = useState(false);
  const [isReportModalOpen, setIsReportModalOpen] = useState(false);
  const [reportText, setReportText] = useState('');
  const [newCommentText, setNewCommentText] = useState('');
  const [showToast, setToastText] = useState<string | null>(null);
  const [toastIsError, setToastIsError] = useState(false);
  const [internalIsSaved, setInternalIsSaved] = useState(false);
  const [isShareOpen, setIsShareOpen] = useState(false);
  // Tipo de archivo que el plan actual no permite descargar (abre el aviso de pago)
  const [lockedDownload, setLockedDownload] = useState<'DWG' | 'SKP' | null>(null);
  const shareRef = useRef<HTMLDivElement>(null);
  const [replyingTo, setReplyingTo] = useState<string | null>(null);
  const [replyText, setReplyText] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [isVoting, setIsVoting] = useState(false);
  // Errores que se muestran arriba de cada campo, en rojo
  const [reportError, setReportError] = useState<string | null>(null);
  const [commentError, setCommentError] = useState<string | null>(null);
  const [replyError, setReplyError] = useState<string | null>(null);
  // Edición de un comentario propio, en el lugar
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editText, setEditText] = useState('');
  const [editError, setEditError] = useState<string | null>(null);

  // Aviso flotante: verde para confirmaciones, rojo para errores
  const setShowToast = (text: string | null) => {
    setToastIsError(false);
    setToastText(text);
  };
  const showError = (text: string) => {
    setToastIsError(true);
    setToastText(text);
  };

  const isOwnDetail = !!currentUser && detail.author.id === currentUser.id;
  const canVote = !!onToggleVote && !isOwnDetail;
  const totalComments = comments.reduce((n, c) => n + 1 + (c.replies?.length ?? 0), 0);

  useEffect(() => {
    setSelectedImage(detail.imageUrl);
    setImageError(false);
    
    // Check if saved from storage if external prop not passed
    if (externalIsFavorite === undefined) {
      const savedItems = JSON.parse(localStorage.getItem('ed_favorites_v7') || localStorage.getItem('ed_saved_items') || '[]');
      setInternalIsSaved(savedItems.includes(detail.id));
    }
  }, [detail.id, detail.imageUrl, externalIsFavorite]);

  const isSaved = externalIsFavorite !== undefined ? externalIsFavorite : internalIsSaved;

  useEffect(() => {
    setImageError(false);
  }, [selectedImage]);

  useEffect(() => {
      if(showToast) {
          const timer = setTimeout(() => setShowToast(null), 3000);
          return () => clearTimeout(timer);
      }
  }, [showToast]);

  const allImages = useMemo(() => [
    detail.imageUrl,
    ...detail.drawings.map(d => d.url)
  ], [detail]);

  const handleNext = (e: React.MouseEvent) => {
    e.stopPropagation();
    const currentIndex = allImages.indexOf(selectedImage);
    const nextIndex = (currentIndex + 1) % allImages.length;
    setSelectedImage(allImages[nextIndex]);
  };

  const handlePrev = (e: React.MouseEvent) => {
    e.stopPropagation();
    const currentIndex = allImages.indexOf(selectedImage);
    const prevIndex = (currentIndex - 1 + allImages.length) % allImages.length;
    setSelectedImage(allImages[prevIndex]);
  };

  const submitReport = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!reportText.trim() || isSending) return;
    // Misma regla que la API, para avisar sin esperar al servidor
    if (reportText.trim().length < 10) {
        setReportError('Contanos un poco más (al menos 10 caracteres).');
        return;
    }
    setIsSending(true);
    setReportError(null);
    try {
        await onReportError(detail.id, reportText);
        setReportText('');
        setIsReportModalOpen(false);
        setShowToast('Reporte enviado correctamente');
    } catch (err) {
        setReportError(errorMessage(err, 'No se pudo enviar el reporte. Probá de nuevo.'));
    } finally {
        setIsSending(false);
    }
  };

  const handleCommentSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newCommentText.trim() || !onAddComment || isSending) return;
    setIsSending(true);
    setCommentError(null);
    try {
      await onAddComment(detail.id, newCommentText);
      setNewCommentText('');
    } catch (err) {
      setCommentError(errorMessage(err, 'No se pudo publicar el comentario. Probá de nuevo.'));
    } finally {
      setIsSending(false);
    }
  };

  const handleReplySubmit = async (e: React.FormEvent, parentId: string) => {
    e.preventDefault();
    if (!replyText.trim() || !onAddComment || isSending) return;
    setIsSending(true);
    setReplyError(null);
    try {
      await onAddComment(detail.id, replyText, parentId);
      setReplyText('');
      setReplyingTo(null);
    } catch (err) {
      setReplyError(errorMessage(err, 'No se pudo publicar la respuesta. Probá de nuevo.'));
    } finally {
      setIsSending(false);
    }
  };

  const handleSaveEdit = async (commentId: string) => {
    if (!onEditComment || !editText.trim() || isSending) return;
    setIsSending(true);
    setEditError(null);
    try {
      await onEditComment(commentId, editText);
      setEditingId(null);
      setShowToast('Comentario actualizado');
    } catch (err) {
      setEditError(errorMessage(err, 'No se pudo guardar el cambio. Probá de nuevo.'));
    } finally {
      setIsSending(false);
    }
  };

  const handleDeleteComment = async (commentId: string) => {
    if (!onDeleteComment || !window.confirm('¿Eliminar este comentario? También se borran sus respuestas.')) return;
    try {
      await onDeleteComment(commentId);
      setShowToast('Comentario eliminado');
    } catch (err) {
      showError(errorMessage(err, 'No se pudo eliminar el comentario'));
    }
  };

  const handleVote = async () => {
    if (!canVote || isVoting) return;
    setIsVoting(true);
    try {
      await onToggleVote!();
    } catch (err) {
      showError(errorMessage(err, 'No se pudo registrar el voto'));
    } finally {
      setIsVoting(false);
    }
  };

  // Un comentario con sus acciones; las respuestas van debajo, un solo nivel
  const renderComment = (comment: Comment, isReply = false) => (
    <div key={comment.id} className="flex gap-4">
        <img src={comment.authorAvatar} className={`${isReply ? 'w-8 h-8' : 'w-10 h-10'} rounded-full object-cover border border-slate-200 dark:border-dark-border`} alt={comment.authorName} />
        <div className="flex-1 min-w-0">
            <div className="flex items-baseline gap-2 mb-1">
                <h4 className="font-bold text-slate-900 dark:text-white text-sm">
                    {onCommentAuthorClick && comment.authorId ? (
                        <button type="button" onClick={() => onCommentAuthorClick({ id: comment.authorId, name: comment.authorName, avatarUrl: comment.authorAvatar, role: '' })} className="hover:text-primary-500 transition-colors">{comment.authorName}</button>
                    ) : comment.authorName}
                </h4>
                {comment.role && <span className="text-[10px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded text-slate-500 dark:text-slate-400 font-bold uppercase">{comment.role}</span>}
                <span className="text-xs text-slate-400 ml-auto text-right" title={comment.timestamp}>
                    {comment.createdAt ? fullDate(comment.createdAt) : comment.timestamp}
                    {comment.edited && <span className="italic"> · editado</span>}
                </span>
            </div>
            {editingId === comment.id ? (
                <div>
                    {editError && <FieldError message={editError} />}
                    <textarea autoFocus value={editText} onChange={(e) => { setEditText(e.target.value); setEditError(null); }} maxLength={2000} className="w-full bg-white dark:bg-dark-card border border-slate-200 dark:border-dark-border rounded-xl p-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary-400 min-h-[70px] resize-y mb-2 dark:text-white" />
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setEditingId(null)} className="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">Cancelar</button>
                        <button type="button" onClick={() => handleSaveEdit(comment.id)} disabled={!editText.trim() || isSending} className="bg-primary-400 hover:bg-primary-500 text-slate-900 px-4 py-2 rounded-lg text-sm font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-colors">Guardar</button>
                    </div>
                </div>
            ) : (
                <p className="text-sm text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-dark-surface p-3 rounded-lg rounded-tl-none border border-slate-100 dark:border-dark-border/50 whitespace-pre-line break-words">{comment.text}</p>
            )}
            <div className="flex items-center gap-4 mt-1.5 ml-1">
                {!isReply && currentUser && onAddComment && (
                    <button type="button" onClick={() => { setReplyingTo(replyingTo === comment.id ? null : comment.id); setReplyText(''); setReplyError(null); }} className="text-xs font-bold text-slate-400 hover:text-primary-500 transition-colors">Responder</button>
                )}
                {comment.canEdit && onEditComment && editingId !== comment.id && (
                    <button type="button" onClick={() => { setEditingId(comment.id); setEditText(comment.text); setEditError(null); }} className="text-xs font-bold text-slate-400 hover:text-primary-500 transition-colors">Editar</button>
                )}
                {comment.canDelete && onDeleteComment && (
                    <button type="button" onClick={() => handleDeleteComment(comment.id)} className="text-xs font-bold text-slate-400 hover:text-red-500 transition-colors">Eliminar</button>
                )}
            </div>
            {!isReply && comment.replies && comment.replies.length > 0 && (
                <div className="mt-4 space-y-4">{comment.replies.map((reply) => renderComment(reply, true))}</div>
            )}
            {replyingTo === comment.id && (
                <form onSubmit={(e) => handleReplySubmit(e, comment.id)} className="mt-3">
                    {replyError && <FieldError message={replyError} />}
                    <textarea autoFocus value={replyText} onChange={(e) => { setReplyText(e.target.value); setReplyError(null); }} placeholder={`Responder a ${comment.authorName}...`} className="w-full bg-white dark:bg-dark-card border border-slate-200 dark:border-dark-border rounded-xl p-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary-400 min-h-[60px] resize-y mb-2 dark:text-white" />
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setReplyingTo(null)} className="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">Cancelar</button>
                        <button type="submit" disabled={!replyText.trim() || isSending} className="bg-primary-400 hover:bg-primary-500 text-slate-900 px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"><Send size={16} /> Responder</button>
                    </div>
                </form>
            )}
        </div>
    </div>
  );

  const shareUrl = detail.slug ? absoluteUrl(detailPath(detail.slug)) : window.location.href;

  // Cerrar el menú de compartir al hacer clic afuera o con Escape
  useEffect(() => {
      if (!isShareOpen) return;
      const onMouseDown = (e: MouseEvent) => {
          if (!shareRef.current?.contains(e.target as Node)) setIsShareOpen(false);
      };
      const onKeyDown = (e: KeyboardEvent) => {
          if (e.key === 'Escape') setIsShareOpen(false);
      };
      document.addEventListener('mousedown', onMouseDown);
      document.addEventListener('keydown', onKeyDown);
      return () => {
          document.removeEventListener('mousedown', onMouseDown);
          document.removeEventListener('keydown', onKeyDown);
      };
  }, [isShareOpen]);

  const handleCopyLink = async () => {
      setIsShareOpen(false);
      try {
          await navigator.clipboard.writeText(shareUrl);
          setShowToast('Enlace copiado al portapapeles');
      } catch {
          showError('No se pudo copiar el enlace');
      }
  };

  const handleShareWhatsApp = () => {
      setIsShareOpen(false);
      const text = encodeURIComponent(`${detail.title} ${shareUrl}`);
      window.open(`https://wa.me/?text=${text}`, '_blank', 'noopener,noreferrer');
  };

  // Instagram no tiene enlace para compartir desde la web: en el celular se abre
  // el menú nativo (ahí aparece Instagram); en la compu se copia el link y se
  // abren los mensajes de Instagram para pegarlo.
  const handleShareInstagram = async () => {
      setIsShareOpen(false);
      const isTouch = window.matchMedia('(pointer: coarse)').matches;
      if (isTouch && navigator.share) {
          try {
              await navigator.share({ url: shareUrl });
              return;
          } catch (e) {
              if ((e as DOMException).name === 'AbortError') return;
          }
      }
      // La copia arranca antes de abrir la pestaña (después la página pierde el foco)
      const copied = navigator.clipboard.writeText(shareUrl);
      window.open('https://www.instagram.com/direct/inbox/', '_blank', 'noopener,noreferrer');
      try {
          await copied;
          setShowToast('Enlace copiado: pegalo en Instagram');
      } catch {
          showError('No se pudo copiar el enlace');
      }
  };

  const hasSkp = !!detail.files?.some((f) => f.type === 'skp');
  const hasDwg = !!detail.files?.some((f) => f.type === 'dwg');

  const handleDownload = async (type: 'DWG' | 'SKP') => {
      const kind = type.toLowerCase() as 'dwg' | 'skp';
      const file = detail.files?.find((f) => f.type === kind);
      if (!file) {
          showError(`Este detalle no tiene archivo ${type}`);
          return;
      }
      // Hay archivo pero el plan no lo incluye: pantalla para comprar el plan
      if (!file.canDownload) {
          setLockedDownload(type);
          return;
      }
      setShowToast(`Iniciando descarga de archivo ${type}...`);
      try {
          await downloadDetailFile(detail.id, kind);
          setShowToast('Descarga completada');
      } catch (err) {
          showError(errorMessage(err, 'No se pudo descargar el archivo'));
      }
  };

  const handleToggleSave = () => {
    if (onToggleFavorite) {
      onToggleFavorite(detail.id);
      const willBeSaved = !isSaved;
      setShowToast(willBeSaved ? '★ Detalle guardado en tus favoritos' : 'Detalle eliminado de favoritos');
    } else {
      const savedItems = JSON.parse(localStorage.getItem('ed_favorites_v7') || localStorage.getItem('ed_saved_items') || '[]');
      let newSavedItems;
      
      if (isSaved) {
        newSavedItems = savedItems.filter((id: string) => id !== detail.id);
        setInternalIsSaved(false);
        setShowToast('Detalle eliminado de favoritos');
      } else {
        newSavedItems = [...savedItems, detail.id];
        setInternalIsSaved(true);
        setShowToast('★ Detalle guardado en tus favoritos');
      }
      
      localStorage.setItem('ed_favorites_v7', JSON.stringify(newSavedItems));
      localStorage.setItem('ed_saved_items', JSON.stringify(newSavedItems));
    }
  };

  return (
    <div className="pb-20 relative">
      
      {/* Toast Notification */}
      {showToast && (
          <div className="fixed bottom-10 left-1/2 -translate-x-1/2 z-[60] bg-slate-900 text-white px-6 py-3 rounded-xl shadow-2xl flex items-center gap-3 animate-in fade-in slide-in-from-bottom-4">
              {toastIsError
                ? <AlertTriangle size={20} className="text-red-400" />
                : <CheckCircle2 size={20} className="text-green-400" />}
              <span className="font-medium text-sm">{showToast}</span>
          </div>
      )}

      {/* Navigation */}
      <div className="flex items-center justify-between mb-6 sticky top-0 bg-slate-50/95 dark:bg-dark-bg/95 backdrop-blur z-30 py-4 border-b border-slate-200/50 dark:border-slate-800/50">
        <button 
          onClick={onBack}
          className="flex items-center gap-2 text-slate-500 dark:text-slate-400 hover:text-primary-500 dark:hover:text-primary-400 transition-colors px-2 py-1 -ml-2 rounded-lg"
        >
          <ArrowLeft size={20} />
          <span className="font-semibold">Volver</span>
        </button>

        <div className="flex items-center gap-2 sm:gap-3">
           <button 
                onClick={() => setIsReportModalOpen(true)}
                className="flex items-center gap-2 px-3 sm:px-4 py-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10 rounded-full text-sm font-bold transition-colors border border-transparent hover:border-red-200"
                title="Reportar Error"
           >
              <AlertTriangle size={16} />
              <span className="hidden md:inline">Reportar Error</span>
           </button>
           
           <button 
                onClick={handleToggleSave}
                className={`flex items-center gap-2 px-3 sm:px-4 py-2 rounded-full border transition-all text-sm font-bold shadow-sm ${
                  isSaved 
                    ? 'bg-amber-400 text-slate-900 border-amber-400 hover:bg-amber-500 shadow-amber-400/20' 
                    : 'bg-white dark:bg-dark-card border-slate-200 dark:border-dark-border text-slate-700 dark:text-slate-200 hover:border-amber-400 hover:text-amber-600 dark:hover:text-amber-400'
                }`}
                title={isSaved ? "Quitar de favoritos" : "Guardar como Favorito"}
           >
              <Bookmark size={16} fill={isSaved ? "currentColor" : "none"} className={isSaved ? "text-slate-900" : "text-amber-500"} />
              <span className="hidden sm:inline">{isSaved ? "En Favoritos" : "Favorito"}</span>
           </button>

           <div className="relative" ref={shareRef}>
             <button
                  onClick={() => setIsShareOpen(!isShareOpen)}
                  className="p-2.5 rounded-full bg-white dark:bg-dark-card border border-slate-200 dark:border-dark-border text-slate-500 dark:text-slate-400 hover:text-primary-500 transition-colors"
                  title="Compartir"
                  aria-haspopup="menu"
                  aria-expanded={isShareOpen}
             >
                <Share2 size={18} />
             </button>
             {isShareOpen && (
               <div role="menu" className="absolute right-0 mt-2 w-52 bg-white dark:bg-dark-card border border-slate-200 dark:border-dark-border rounded-xl shadow-xl py-1 z-40">
                 <button role="menuitem" onClick={handleShareWhatsApp} className="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                   <MessageCircle size={16} className="text-green-500" />
                   WhatsApp
                 </button>
                 <button role="menuitem" onClick={handleShareInstagram} className="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                   <Instagram size={16} className="text-pink-500" />
                   Instagram
                 </button>
                 <button role="menuitem" onClick={handleCopyLink} className="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                   <Link2 size={16} className="text-slate-400" />
                   Copiar enlace
                 </button>
               </div>
             )}
           </div>
           <button onClick={() => handleDownload('SKP')} disabled={!hasSkp} title={hasSkp ? undefined : 'Este detalle no tiene archivo SketchUp'} className={`hidden md:flex items-center gap-2 px-4 py-2.5 border rounded-full text-sm font-bold transition-all ${hasSkp ? 'bg-white dark:bg-dark-card border-slate-200 dark:border-dark-border hover:border-primary-400 text-slate-700 dark:text-slate-200' : 'bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-400 opacity-50 cursor-not-allowed'}`}>
              <BoxSelect size={18} />
              <span>SketchUp</span>
           </button>
           <button onClick={() => handleDownload('DWG')} disabled={!hasDwg} title={hasDwg ? undefined : 'Este detalle no tiene archivo DWG'} className={`flex items-center gap-2 px-5 py-2.5 rounded-full text-sm font-bold transition-transform ${hasDwg ? 'bg-primary-400 hover:bg-primary-500 text-slate-900 hover:scale-105 shadow-lg shadow-primary-400/20' : 'bg-slate-200 dark:bg-slate-700 text-slate-400 opacity-50 cursor-not-allowed'}`}>
              <Download size={18} />
              <span className="hidden sm:inline">Descargar DWG</span>
           </button>
        </div>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-12 gap-8">
        
        {/* LEFT COLUMN: Images */}
        <div className="xl:col-span-7 space-y-4">
             <div className="relative rounded-3xl overflow-hidden border border-slate-200 dark:border-dark-border shadow-sm bg-slate-900 group aspect-[4/5] select-none">
                {imageError ? (
                  <div className="w-full h-full flex flex-col items-center justify-center bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500">
                    <ImageOff size={48} className="mb-3 opacity-50" />
                    <p className="text-sm font-bold">Imagen no disponible</p>
                    <p className="text-xs mt-1 opacity-70">No se pudo cargar el recurso</p>
                  </div>
                ) : (
                  <img 
                    src={selectedImage} 
                    className="w-full h-full object-cover transition-opacity duration-300" 
                    alt="Detalle principal" 
                    onError={() => {
                        console.error(`Error loading image: ${selectedImage}`);
                        setImageError(true);
                    }}
                  />
                )}
                
                {allImages.length > 1 && !imageError && (
                  <>
                    <button onClick={handlePrev} className="absolute left-4 top-1/2 -translate-y-1/2 p-3 rounded-full bg-black/40 text-white hover:bg-primary-400 hover:text-slate-900 backdrop-blur-md border border-white/10 transition-all opacity-0 group-hover:opacity-100 z-10 transform hover:scale-110 active:scale-95 duration-300"><ChevronLeft size={24} /></button>
                    <button onClick={handleNext} className="absolute right-4 top-1/2 -translate-y-1/2 p-3 rounded-full bg-black/40 text-white hover:bg-primary-400 hover:text-slate-900 backdrop-blur-md border border-white/10 transition-all opacity-0 group-hover:opacity-100 z-10 transform hover:scale-110 active:scale-95 duration-300"><ChevronRight size={24} /></button>
                    <div className="absolute top-4 right-4 bg-black/60 backdrop-blur text-white text-xs font-bold px-3 py-1 rounded-full border border-white/10 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                      {allImages.indexOf(selectedImage) + 1} / {allImages.length}
                    </div>
                  </>
                )}

                {!imageError && (
                  <button onClick={() => window.open(selectedImage, '_blank')} className="absolute bottom-4 right-4 p-3 bg-black/50 backdrop-blur-md rounded-full text-white hover:bg-primary-400 hover:text-black transition-all shadow-lg z-10 opacity-0 group-hover:opacity-100 duration-300"><Maximize2 size={20} /></button>
                )}
             </div>
             
             {allImages.length > 1 && (
               <div className="flex gap-3 overflow-x-auto pb-2 scrollbar-hide">
                  {allImages.map((imgUrl, idx) => (
                    <button key={idx} onClick={() => setSelectedImage(imgUrl)} className={`relative w-24 h-32 rounded-xl overflow-hidden border-2 flex-shrink-0 transition-all ${selectedImage === imgUrl ? 'border-primary-400 scale-105 shadow-md' : 'border-transparent opacity-70 hover:opacity-100'} bg-slate-100 dark:bg-slate-800`}>
                      <img src={imgUrl} className="w-full h-full object-cover" onError={(e) => { e.currentTarget.src = PLACEHOLDER_IMG; }} />
                    </button>
                  ))}
               </div>
             )}
        </div>

        {/* RIGHT COLUMN: Info */}
        <div className="xl:col-span-5 space-y-8">
            <div>
                <div className="flex flex-wrap items-center gap-3 mb-4">
                    <span className="px-3 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-bold uppercase tracking-wider">{detail.category}</span>
                    {detail.isVerified && <span className="flex items-center gap-1.5 text-xs font-bold text-slate-900 bg-primary-400 px-3 py-1 rounded-full"><CheckCircle2 size={14} /> Verificado por el equipo de EnDetalle</span>}
                </div>
                <h1 className="text-4xl md:text-5xl font-bold text-slate-900 dark:text-white mb-6 leading-tight tracking-tight">{detail.title}</h1>
                <button
                   type="button"
                   onClick={handleVote}
                   disabled={!canVote || isVoting}
                   aria-pressed={!!detail.hasVoted}
                   title={isOwnDetail ? 'No podés votar tu propio detalle' : detail.hasVoted ? 'Quitar mi voto' : 'Votar este detalle'}
                   className="flex items-center gap-2 text-primary-500 dark:text-primary-400 enabled:hover:scale-105 transition-transform disabled:cursor-default"
                >
                   {/* Corazón vacío = todavía no votaste */}
                   <Heart className={detail.hasVoted || !canVote ? "fill-current" : ""} size={24} />
                   <span className="text-xl font-bold text-slate-900 dark:text-white">{detail.likes}</span>
                   <span className="text-sm text-slate-500 dark:text-slate-400 font-medium">valoraciones</span>
                </button>
            </div>

            <div onClick={() => onAuthorClick(detail.author)} className="bg-white dark:bg-dark-surface p-5 rounded-2xl border border-slate-200 dark:border-dark-border shadow-sm flex items-center gap-5 cursor-pointer hover:border-primary-400 transition-colors group">
                <div className="relative">
                    <img src={detail.author.avatarUrl} className="w-16 h-16 rounded-full object-cover border-2 border-primary-400 group-hover:scale-105 transition-transform" alt={detail.author.name} />
                    {detail.isVerified && (
                      <div className="absolute -bottom-1 -right-1 bg-primary-400 text-slate-900 rounded-full p-1 border-2 border-white dark:border-dark-surface" title="Verificado por el equipo de EnDetalle"><CheckCircle2 size={12} /></div>
                    )}
                </div>
                <div className="flex-1">
                    <h3 className="text-lg font-bold text-slate-900 dark:text-white group-hover:text-primary-500 transition-colors">{detail.author.name}</h3>
                    <p className="text-sm font-medium text-primary-600 dark:text-primary-400">{detail.author.role}</p>
                    {detail.author.team && <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{detail.author.team}</p>}
                </div>
                <button className="text-xs font-bold bg-slate-100 dark:bg-slate-700 hover:bg-primary-400 hover:text-slate-900 px-4 py-2 rounded-lg transition-colors text-slate-700 dark:text-slate-200">Ver Perfil</button>
            </div>

            <div className="prose dark:prose-invert">
                <p className="text-slate-600 dark:text-slate-300 text-base leading-relaxed">{detail.description}</p>
            </div>

            <div className="bg-slate-50 dark:bg-dark-surface/50 rounded-2xl p-6 border border-slate-100 dark:border-dark-border space-y-6">
                <div>
                    <h3 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2"><Box size={14} /> Especificaciones</h3>
                    <ul className="space-y-3">
                        {detail.specs.map((spec, idx) => (
                        <li key={idx} className="text-sm text-slate-700 dark:text-slate-300 flex items-start gap-3">
                            <span className="w-1.5 h-1.5 rounded-full bg-primary-400 mt-2 shrink-0"></span>{spec}
                        </li>
                        ))}
                    </ul>
                </div>
                <div className="border-t border-slate-200 dark:border-dark-border pt-6">
                    <h3 className="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2"><Layers size={14} /> Materiales</h3>
                    <div className="grid grid-cols-1 gap-2">
                        {detail.materials.map((mat, idx) => (
                            <div key={idx} className="flex items-center gap-3 bg-white dark:bg-dark-card p-3 rounded-lg border border-slate-200 dark:border-dark-border/50 text-sm font-medium text-slate-700 dark:text-slate-200">
                                <span className="flex items-center justify-center w-5 h-5 rounded bg-slate-100 dark:bg-slate-800 text-xs font-bold text-slate-500">{idx + 1}</span>{mat}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

             <div className="border-t border-slate-200 dark:border-dark-border pt-8">
                <h3 className="text-xl font-bold text-slate-900 dark:text-white mb-6 flex items-center gap-2"><MessageSquare size={20} className="text-primary-500" /> Comentarios <span className="text-slate-400 text-sm font-normal">({totalComments})</span></h3>
                <div className="space-y-6 mb-8">
                    {comments.length === 0 ? <p className="text-slate-500 dark:text-slate-400 text-sm italic">No hay comentarios aún. Sé el primero en opinar.</p> : comments.map((comment) => renderComment(comment))}
                </div>
                {onAddComment && (
                    <form onSubmit={handleCommentSubmit} className="flex gap-4 items-start">
                        {currentUser ? (
                             <img src={currentUser.avatarUrl} className="w-10 h-10 rounded-full object-cover border-2 border-slate-200 dark:border-dark-border" />
                        ) : (
                             <div className="w-10 h-10 rounded-full bg-slate-200 dark:bg-dark-card flex items-center justify-center text-slate-400"><span className="text-xs font-bold">YO</span></div>
                        )}
                        <div className="flex-1">
                            {commentError && <FieldError message={commentError} />}
                            <textarea value={newCommentText} onChange={(e) => { setNewCommentText(e.target.value); setCommentError(null); }} placeholder="Escribe una pregunta o comentario..." className="w-full bg-white dark:bg-dark-card border border-slate-200 dark:border-dark-border rounded-xl p-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary-400 min-h-[80px] resize-y mb-2 dark:text-white" />
                            <div className="flex justify-end">
                                <button type="submit" disabled={!newCommentText.trim() || isSending} className="bg-primary-400 hover:bg-primary-500 text-slate-900 px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"><Send size={16} /> Publicar</button>
                            </div>
                        </div>
                    </form>
                )}
             </div>
        </div>
      </div>

      {relatedDetails.length > 0 && (
          <div className="mt-20 border-t border-slate-200 dark:border-dark-border pt-10">
              <div className="flex items-center justify-between mb-6">
                  <h3 className="text-xl font-bold text-slate-900 dark:text-white">También te podría interesar</h3>
                  <button className="text-sm font-bold text-primary-600 dark:text-primary-400 hover:underline flex items-center gap-1">Ver categoría completa <ArrowRight size={16} /></button>
              </div>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
                  {relatedDetails.map((item) => (
                      <div key={item.id} className="group cursor-pointer" onClick={() => { if(onSelectDetail) onSelectDetail(item); }}>
                          <div className="aspect-[3/4] rounded-xl overflow-hidden mb-3 bg-slate-200 dark:bg-dark-surface relative flex items-center justify-center">
                              <img src={item.imageUrl} className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" onError={(e) => { e.currentTarget.src = PLACEHOLDER_IMG; }} />
                              <div className="absolute inset-0 bg-black/20 group-hover:bg-transparent transition-colors" />
                          </div>
                          <h4 className="font-bold text-slate-800 dark:text-slate-200 text-sm leading-tight group-hover:text-primary-500 transition-colors">{item.title}</h4>
                          <p className="text-xs text-slate-500 dark:text-slate-500 mt-1">{item.author.name}</p>
                      </div>
                  ))}
              </div>
          </div>
      )}

      {lockedDownload && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-slate-900/80 backdrop-blur-sm" onClick={() => setLockedDownload(null)}></div>
          <div className="relative bg-white dark:bg-dark-surface w-full max-w-md rounded-3xl p-8 text-center shadow-2xl border border-slate-200 dark:border-dark-border">
            <button onClick={() => setLockedDownload(null)} aria-label="Cerrar" className="absolute top-4 right-4 p-1 text-slate-400 hover:text-slate-700 dark:hover:text-white"><X size={20} /></button>
            <div className="w-16 h-16 rounded-full bg-slate-900 border-4 border-primary-400 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-primary-400/30">
              <Lock size={30} className="text-primary-400" />
            </div>
            <h2 className="text-2xl font-bold text-slate-900 dark:text-white mb-2">Desbloqueá esta descarga</h2>
            <p className="text-slate-500 dark:text-slate-400 mb-6">
              {lockedDownload === 'SKP'
                ? 'Los archivos SketchUp (.SKP) se incluyen con el plan Pro. Comprá el plan Pro para descargar este modelo 3D.'
                : 'La descarga de planos DWG se incluye con los planes Estudiante y Pro. Elegí un plan para descargar este archivo.'}
            </p>
            <button
              onClick={() => { setLockedDownload(null); onViewPlans?.(); }}
              className="w-full bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold py-3.5 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg shadow-primary-400/20 mb-3"
            >
              <Crown size={18} /> {lockedDownload === 'SKP' ? 'Comprar el plan Pro' : 'Ver planes'}
            </button>
            <button onClick={() => setLockedDownload(null)} className="text-sm text-slate-500 hover:text-slate-900 dark:hover:text-white font-medium">Ahora no</button>
          </div>
        </div>
      )}

      {isReportModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
            <div className="bg-white dark:bg-dark-surface w-full max-w-md rounded-2xl p-6 shadow-2xl animate-in zoom-in-95">
                <div className="flex justify-between items-center mb-4">
                    <h3 className="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2"><AlertTriangle className="text-red-500" /> Reportar Error</h3>
                    <button onClick={() => { setIsReportModalOpen(false); setReportError(null); }} className="text-slate-400 hover:text-slate-600"><ArrowRight className="rotate-45" size={24} /></button>
                </div>
                <p className="text-sm text-slate-600 dark:text-slate-300 mb-4">Ayúdanos a mantener la calidad. Describe el error técnico que encontraste en este detalle.</p>
                <form onSubmit={submitReport}>
                    {reportError && <FieldError message={reportError} />}
                    <textarea className="w-full h-32 p-3 bg-slate-50 dark:bg-dark-bg border border-slate-200 dark:border-dark-border rounded-xl focus:ring-2 focus:ring-red-400 outline-none resize-none mb-4 text-sm" placeholder="Ej: La especificación del hormigón no coincide con la normativa vigente..." value={reportText} onChange={(e) => { setReportText(e.target.value); setReportError(null); }} required />
                    <div className="flex gap-3">
                        <button type="button" onClick={() => { setIsReportModalOpen(false); setReportError(null); }} className="flex-1 py-3 font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition-colors">Cancelar</button>
                        <button type="submit" disabled={isSending} className="flex-1 py-3 font-bold disabled:opacity-50 bg-red-500 text-white hover:bg-red-600 rounded-xl transition-colors flex items-center justify-center gap-2"><Send size={18} /> Enviar Reporte</button>
                    </div>
                </form>
            </div>
        </div>
      )}
    </div>
  );
};

export default DetailView;
