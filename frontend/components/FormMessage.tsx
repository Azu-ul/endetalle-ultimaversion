import React from "react";
import { AlertTriangle, CheckCircle2 } from "lucide-react";

// Mensaje debajo o arriba de un formulario: rojo para errores, verde para confirmaciones
const FormMessage: React.FC<{ type: "error" | "success"; text: string }> = ({
  type,
  text,
}) => (
  <p
    role={type === "error" ? "alert" : "status"}
    className={`text-sm font-semibold flex items-center gap-1.5 ${
      type === "error" ? "text-red-500" : "text-green-600 dark:text-green-400"
    }`}
  >
    {type === "error" ? (
      <AlertTriangle size={14} className="shrink-0" />
    ) : (
      <CheckCircle2 size={14} className="shrink-0" />
    )}
    {text}
  </p>
);

export default FormMessage;
