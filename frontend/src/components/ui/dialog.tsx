"use client";
import * as Primitive from "@radix-ui/react-dialog";
import type { ReactNode } from "react";
import { X } from "lucide-react";
import { buttonVariants } from "./button";

export function Dialog({
  trigger,
  title,
  description,
  children,
}: {
  trigger: ReactNode;
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <Primitive.Root>
      <Primitive.Trigger asChild>{trigger}</Primitive.Trigger>
      <Primitive.Portal>
        <Primitive.Overlay className="dialog-overlay" />
        <Primitive.Content className="dialog-panel">
          <Primitive.Title className="text-heading-3">{title}</Primitive.Title>
          <Primitive.Description className="supporting">
            {description}
          </Primitive.Description>
          {children}
          <Primitive.Close className={buttonVariants({ variant: "outline" })}>
            <X aria-hidden size={18} /> Tutup
          </Primitive.Close>
        </Primitive.Content>
      </Primitive.Portal>
    </Primitive.Root>
  );
}
