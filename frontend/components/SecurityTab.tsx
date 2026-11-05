import React, { useEffect, useState } from "react";
import { KeyRound, Laptop, LogOut, Smartphone } from "lucide-react";
import {
  AccountApi,
  ApiSession,
  errorText,
  timeAgo,
} from "../services/api";
import FormMessage from "./FormMessage";
import PasswordInput from "./PasswordInput";

const cardClass =
  "bg-white dark:bg-dark-surface p-8 rounded-3xl border border-slate-200 dark:border-dark-border shadow-sm";
const labelClass = "block text-xs font-bold uppercase text-slate-500 mb-2";
const inputClass =
  "w-full bg-slate-50 dark:bg-dark-bg p-3 rounded-xl border border-slate-200 dark:border-dark-border dark:text-white";
const primaryButtonClass =
  "px-6 py-3 bg-primary-400 hover:bg-primary-500 text-slate-900 font-bold rounded-xl shadow-lg disabled:opacity-50 disabled:cursor-not-allowed";

type Message = { type: "error" | "success"; text: string } | null;

const EMPTY_PASSWORDS = {
  current_password: "",
  password: "",
  password_confirmation: "",
};

// Pestaña "Seguridad" de Mi cuenta: cambio de contraseña y sesiones abiertas
const SecurityTab: React.FC = () => {
  const [passwords, setPasswords] = useState(EMPTY_PASSWORDS);
  const [passwordMessage, setPasswordMessage] = useState<Message>(null);
  const [isSaving, setIsSaving] = useState(false);

  const [sessions, setSessions] = useState<ApiSession[] | null>(null);
  const [confirmPassword, setConfirmPassword] = useState<string | null>(null);
  const [sessionsMessage, setSessionsMessage] = useState<Message>(null);

  const loadSessions = () =>
    AccountApi.sessions()
      .then(setSessions)
      .catch(() => setSessions([]));

  useEffect(() => {
    loadSessions();
  }, []);

  const handleChangePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    setPasswordMessage(null);
    try {
      const res = await AccountApi.changePassword(passwords);
      setPasswords(EMPTY_PASSWORDS);
      setPasswordMessage({
        type: "success",
        text:
          res.closed_sessions > 0
            ? "Contraseña actualizada. Cerramos tu sesión en los otros dispositivos."
            : "Contraseña actualizada.",
      });
      loadSessions();
    } catch (err) {
      setPasswordMessage({
        type: "error",
        text: errorText(err, "No se pudo cambiar la contraseña. Probá de nuevo."),
      });
    } finally {
      setIsSaving(false);
    }
  };

  const handleLogoutOthers = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!confirmPassword) return;
    setSessionsMessage(null);
    try {
      const res = await AccountApi.logoutOtherSessions(confirmPassword);
      setConfirmPassword(null);
      setSessionsMessage({
        type: "success",
        text:
          res.closed_sessions === 1
            ? "Cerramos 1 sesión."
            : `Cerramos ${res.closed_sessions} sesiones.`,
      });
      loadSessions();
    } catch (err) {
      setSessionsMessage({
        type: "error",
        text: errorText(err, "No se pudieron cerrar las sesiones. Probá de nuevo."),
      });
    }
  };

  const otherSessions = (sessions ?? []).filter((s) => !s.current).length;

  return (
    <div className="space-y-6">
      <div className={cardClass}>
        <h2 className="text-2xl font-bold dark:text-white mb-6 flex items-center gap-2">
          <KeyRound size={22} className="text-primary-500" /> Cambiar
          contraseña
        </h2>
        <form onSubmit={handleChangePassword} className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <label className={labelClass}>Contraseña actual</label>
              <PasswordInput
                autoComplete="current-password"
                required
                value={passwords.current_password}
                onChange={(e) =>
                  setPasswords({ ...passwords, current_password: e.target.value })
                }
                className={inputClass}
              />
            </div>
            <div>
              <label className={labelClass}>Contraseña nueva</label>
              <PasswordInput
                autoComplete="new-password"
                required
                minLength={8}
                value={passwords.password}
                onChange={(e) =>
                  setPasswords({ ...passwords, password: e.target.value })
                }
                className={inputClass}
              />
            </div>
            <div>
              <label className={labelClass}>Repetí la nueva</label>
              <PasswordInput
                autoComplete="new-password"
                required
                minLength={8}
                value={passwords.password_confirmation}
                onChange={(e) =>
                  setPasswords({
                    ...passwords,
                    password_confirmation: e.target.value,
                  })
                }
                className={inputClass}
              />
            </div>
          </div>
          <p className="text-xs text-slate-400">
            Mínimo 8 caracteres. Al cambiarla se cierra tu sesión en los otros
            dispositivos.
          </p>
          <div className="flex flex-wrap items-center justify-end gap-4">
            {passwordMessage && (
              <div className="mr-auto">
                <FormMessage {...passwordMessage} />
              </div>
            )}
            <button type="submit" disabled={isSaving} className={primaryButtonClass}>
              {isSaving ? "Guardando..." : "Cambiar contraseña"}
            </button>
          </div>
        </form>
      </div>

      <div className={cardClass}>
        <h2 className="text-2xl font-bold dark:text-white mb-2">
          Sesiones abiertas
        </h2>
        <p className="text-sm text-slate-500 dark:text-slate-400 mb-6">
          Si ves un dispositivo que no reconocés, cerrá las demás sesiones y
          cambiá tu contraseña.
        </p>

        {sessions === null ? (
          <p className="text-sm text-slate-400">Cargando...</p>
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-dark-border mb-6">
            {sessions.map((s, i) => {
              const isPhone = /Android|iPhone|iPad/.test(s.device);
              return (
                <li key={i} className="flex items-center gap-4 py-3">
                  <div className="w-10 h-10 rounded-xl bg-slate-100 dark:bg-dark-bg flex items-center justify-center text-slate-500">
                    {isPhone ? <Smartphone size={18} /> : <Laptop size={18} />}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="font-bold text-sm text-slate-900 dark:text-white">
                      {s.device}
                    </p>
                    <p className="text-xs text-slate-500 dark:text-slate-400">
                      {s.ip_address ?? "IP desconocida"} · activa{" "}
                      {timeAgo(s.last_activity).toLowerCase()}
                    </p>
                  </div>
                  {s.current && (
                    <span className="text-[10px] bg-primary-100 dark:bg-primary-950/60 text-primary-900 dark:text-primary-300 px-2 py-1 rounded-full font-bold uppercase">
                      Este dispositivo
                    </span>
                  )}
                </li>
              );
            })}
          </ul>
        )}

        {sessionsMessage && (
          <div className="mb-4">
            <FormMessage {...sessionsMessage} />
          </div>
        )}

        {confirmPassword === null ? (
          otherSessions > 0 && (
            <button
              type="button"
              onClick={() => {
                setConfirmPassword("");
                setSessionsMessage(null);
              }}
              className="flex items-center gap-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 px-4 py-2.5 rounded-xl font-bold text-sm transition-colors"
            >
              <LogOut size={16} /> Cerrar las demás sesiones
            </button>
          )
        ) : (
          <form
            onSubmit={handleLogoutOthers}
            className="flex flex-col sm:flex-row sm:items-end gap-3"
          >
            <div className="flex-1">
              <label className={labelClass}>
                Confirmá con tu contraseña
              </label>
              <PasswordInput
                autoComplete="current-password"
                autoFocus
                required
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                className={inputClass}
              />
            </div>
            <button
              type="button"
              onClick={() => setConfirmPassword(null)}
              className="px-6 py-3 font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-dark-bg rounded-xl"
            >
              Cancelar
            </button>
            <button
              type="submit"
              className="px-6 py-3 bg-red-500 hover:bg-red-600 text-white font-bold rounded-xl"
            >
              Cerrar sesiones
            </button>
          </form>
        )}
      </div>
    </div>
  );
};

export default SecurityTab;
