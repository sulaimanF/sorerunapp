import type { ComponentProps, ReactNode } from "react";
import { useState } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Input } from "@/components/ui/input";

export function FormField({
  name,
  label,
  error,
  help,
  optional = false,
  children,
}: {
  name: string;
  label: string;
  error?: string;
  help?: string;
  optional?: boolean;
  children: ReactNode;
}) {
  return (
    <div className="form-field">
      <label htmlFor={name}>
        {label}{" "}
        {optional ? (
          <span className="muted-label">(opsional)</span>
        ) : (
          <span aria-hidden className="required-marker">
            *
          </span>
        )}
      </label>
      {children}
      {help && (
        <p className="supporting" id={`${name}-help`}>
          {help}
        </p>
      )}
      {error && (
        <p className="field-error" id={`${name}-error`} role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
export function PasswordInput(props: ComponentProps<"input">) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="password-field">
      <Input
        {...props}
        type={visible ? "text" : "password"}
        className="password-input"
      />
      <button
        type="button"
        className="password-toggle"
        onClick={() => setVisible(!visible)}
        aria-label={visible ? "Sembunyikan password" : "Tampilkan password"}
        aria-pressed={visible}
        aria-controls={props.id}
      >
        {visible ? (
          <EyeOff size={20} aria-hidden />
        ) : (
          <Eye size={20} aria-hidden />
        )}
      </button>
    </div>
  );
}
