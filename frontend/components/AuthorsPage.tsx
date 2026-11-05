
import React, { useEffect, useState } from 'react';
import { Author } from '../types';
import { CheckCircle2, ArrowRight, Heart, Search } from 'lucide-react';
import { UsersApi, toPublicUser } from '../services/api';

interface AuthorsPageProps {
  onAuthorClick: (author: Author) => void;
  currentUserId?: string;
  onToggleLike?: (author: Author) => Promise<{ liked: boolean; likes_count: number } | null>;
}

// Directorio de la comunidad: los autores y usuarios con más "me gusta" primero
const AuthorsPage: React.FC<AuthorsPageProps> = ({ onAuthorClick, currentUserId, onToggleLike }) => {
  const [authors, setAuthors] = useState<Author[]>([]);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [isLoading, setIsLoading] = useState(true);

  // Búsqueda con una pausa corta para no pedir en cada tecla
  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    const timer = setTimeout(() => {
      UsersApi.directory(search.trim(), page)
        .then((res) => {
          if (cancelled) return;
          const list = res.data.map(toPublicUser);
          setAuthors((prev) => (page === 1 ? list : [...prev, ...list]));
          setLastPage(res.last_page);
        })
        .catch(console.error)
        .finally(() => {
          if (!cancelled) setIsLoading(false);
        });
    }, page === 1 ? 300 : 0);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [search, page]);

  const handleLike = async (author: Author) => {
    if (!onToggleLike) return;
    try {
      const res = await onToggleLike(author);
      if (!res) return;
      setAuthors((list) =>
        list.map((a) => (a.id === author.id ? { ...a, likedByMe: res.liked, likesCount: res.likes_count } : a)),
      );
    } catch {
      alert('No se pudo registrar el "me gusta". Probá de nuevo.');
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-6 md:px-10 py-12 animate-in slide-in-from-bottom-4 duration-500">
        <div className="text-center max-w-2xl mx-auto mb-10">
            <span className="text-primary-600 dark:text-primary-400 font-bold tracking-widest uppercase text-sm">Comunidad Experta</span>
            <h1 className="text-4xl md:text-5xl font-bold text-slate-900 dark:text-white mt-2 mb-6">Autores Destacados</h1>
            <p className="text-slate-500 dark:text-slate-400 text-lg">
                Conoce a los arquitectos e ingenieros que están construyendo la biblioteca técnica más grande del mundo.
            </p>
        </div>

        <div className="relative max-w-md mx-auto mb-12">
            <Search size={18} className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
                type="search"
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value);
                  setPage(1);
                }}
                placeholder="Buscar por nombre, profesión o estudio..."
                className="w-full pl-11 pr-4 py-3 rounded-2xl bg-white dark:bg-dark-surface border border-slate-200 dark:border-dark-border text-sm focus:outline-none focus:ring-2 focus:ring-primary-400 dark:text-white"
            />
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            {authors.map((author) => {
                const isMe = !!currentUserId && currentUserId === author.id;
                return (
                <div key={author.id} className="bg-white dark:bg-dark-surface rounded-3xl border border-slate-200 dark:border-dark-border p-6 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group">
                    <div className="flex items-start justify-between mb-6">
                        <div className="relative">
                            <img src={author.avatarUrl} alt={author.name} className="w-20 h-20 rounded-2xl object-cover border-2 border-white dark:border-dark-bg shadow-md group-hover:scale-105 transition-transform" />
                            {author.isAuthor && (
                              <div className="absolute -bottom-2 -right-2 bg-primary-400 p-1.5 rounded-full border-2 border-white dark:border-dark-bg" title="Autor de enDetalle">
                                  <CheckCircle2 size={16} className="text-slate-900" />
                              </div>
                            )}
                        </div>
                        {!isMe && onToggleLike && (
                          <button
                              onClick={() => handleLike(author)}
                              title={author.likedByMe ? 'Quitar "me gusta"' : 'Me gusta'}
                              aria-pressed={!!author.likedByMe}
                              className={`p-2 rounded-xl transition-colors ${author.likedByMe ? 'bg-primary-400 text-slate-900' : 'bg-slate-50 dark:bg-dark-bg text-slate-600 dark:text-slate-300 hover:bg-primary-400 hover:text-slate-900'}`}
                          >
                              <Heart size={20} className={author.likedByMe ? 'fill-current' : ''} />
                          </button>
                        )}
                    </div>

                    <h3 className="text-xl font-bold text-slate-900 dark:text-white mb-1 group-hover:text-primary-500 transition-colors">{author.name}</h3>
                    <p className="text-sm font-medium text-slate-500 dark:text-slate-400 mb-4">{author.role}</p>

                    {author.team && (
                        <div className="bg-slate-50 dark:bg-dark-bg px-3 py-2 rounded-lg inline-block text-xs font-bold text-slate-600 dark:text-slate-300 mb-6">
                            {author.team}
                        </div>
                    )}

                    <div className="flex items-center justify-between border-t border-slate-100 dark:border-dark-border pt-4 mt-auto">
                        <div className="flex flex-col">
                            <span className="text-lg font-bold text-slate-900 dark:text-white">{author.detailsCount ?? 0}</span>
                            <span className="text-xs uppercase font-bold text-slate-400">Posts</span>
                        </div>
                        <div className="h-8 w-px bg-slate-100 dark:bg-dark-border"></div>
                         <div className="flex flex-col">
                            <span className="text-lg font-bold text-slate-900 dark:text-white">{author.likesCount ?? 0}</span>
                            <span className="text-xs uppercase font-bold text-slate-400">Me gusta</span>
                        </div>
                        <button onClick={() => onAuthorClick(author)} className="text-primary-600 dark:text-primary-400 font-bold text-sm flex items-center gap-1 hover:gap-2 transition-all">
                            Perfil <ArrowRight size={16} />
                        </button>
                    </div>
                </div>
            )})}
        </div>

        {!isLoading && authors.length === 0 && (
            <p className="text-center py-16 text-slate-500 dark:text-slate-400">
                {search.trim() ? `No encontramos a nadie con "${search.trim()}".` : 'Todavía no hay miembros para mostrar.'}
            </p>
        )}

        {page < lastPage && (
            <div className="text-center mt-12">
                <button
                    onClick={() => setPage((p) => p + 1)}
                    disabled={isLoading}
                    className="px-6 py-3 bg-slate-900 text-white dark:bg-primary-400 dark:text-slate-900 rounded-xl font-bold text-sm hover:opacity-90 transition-opacity disabled:opacity-50"
                >
                    {isLoading ? 'Cargando...' : 'Ver más'}
                </button>
            </div>
        )}
    </div>
  );
};

export default AuthorsPage;
