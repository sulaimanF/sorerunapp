"use strict";
const fs = require("node:fs");
const path = require("node:path");
const root = path.resolve(__dirname, "..");
const ts = require(path.join(root, "frontend/node_modules/typescript"));
const edits = [];
const warnings = [];
const css = `/* BEGIN: SRC stage07 logout confirmation */
.logout-confirmation-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.logout-confirmation-actions > .button {
  flex: 1 1 8rem;
  min-width: 0;
}
/* END: SRC stage07 logout confirmation */`;
function read(relative) {
  const filename = path.join(root, relative);
  if (!fs.existsSync(filename)) throw new Error(`File belum tersedia: ${relative}`);
  return fs.readFileSync(filename, "utf8");
}
function plan(relative, previous, next) {
  if (previous !== next) edits.push({ relative, previous, next });
}
function parse(source, filename, kind = ts.ScriptKind.TS) {
  const file = ts.createSourceFile(filename, source, ts.ScriptTarget.Latest, true, kind);
  if (file.parseDiagnostics.length) throw new Error(`Syntax belum valid: ${filename}`);
  return file;
}
function walk(node, visit) {
  visit(node);
  ts.forEachChild(node, child => walk(child, visit));
}
function isLogoutLocator(node) {
  let matched = false;
  walk(node, child => {
    if (!ts.isCallExpression(child) || !ts.isPropertyAccessExpression(child.expression) || child.expression.name.text !== "getByRole") return;
    const [role, options] = child.arguments;
    if (!role || !ts.isStringLiteral(role) || role.text !== "button" || !options || !ts.isObjectLiteralExpression(options)) return;
    matched = options.properties.some(property => ts.isPropertyAssignment(property) && property.name.getText().replace(/["']/g, "") === "name" && ts.isStringLiteral(property.initializer) && property.initializer.text === "Keluar dari akun") || matched;
  });
  return matched;
}
function patchBrowserTest(relative) {
  const source = read(relative);
  const file = parse(source, relative);
  const aliases = new Map();
  walk(file, node => {
    if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer && isLogoutLocator(node.initializer)) {
      aliases.set(node.name.text, node.initializer);
    }
  });
  const changes = [];
  walk(file, node => {
    if (!ts.isExpressionStatement(node) || !ts.isAwaitExpression(node.expression)) return;
    const call = node.expression.expression;
    if (!ts.isCallExpression(call) || !ts.isPropertyAccessExpression(call.expression) || call.expression.name.text !== "click") return;
    const receiver = call.expression.expression;
    const locator = ts.isIdentifier(receiver) ? aliases.get(receiver.text) : receiver;
    if (!locator || !isLogoutLocator(locator)) return;
    const trailing = source.slice(node.end);
    if (/^\s*\/\/ stage07: confirm logout\b/.test(trailing)) return;
    const pageName = locator.getText(file).match(/^([A-Za-z_$][\w$]*)\./)?.[1];
    if (!pageName) throw new Error(`Locator logout tidak dikenali: ${relative}. Tidak ada source yang diubah.`);
    const lineStart = source.lastIndexOf("\n", node.getStart(file)) + 1;
    const indent = source.slice(lineStart, node.getStart(file)).match(/^[\t ]*/)[0];
    const newline = source.includes("\r\n") ? "\r\n" : "\n";
    changes.push({ at: node.end, text: `${newline}${indent}// stage07: confirm logout before expecting the signed-out result.${newline}${indent}await ${pageName}.getByRole("dialog", { name: "Keluar dari akun?", exact: true }).getByRole("button", { name: "Ya, Keluar", exact: true }).click();` });
  });
  let next = source;
  for (const change of changes.sort((a, b) => b.at - a.at)) next = next.slice(0, change.at) + change.text + next.slice(change.at);
  if (changes.length) parse(next, relative);
  plan(relative, source, next);
}
try {
  const component = "frontend/src/features/auth/components/logout-confirmation.tsx";
  parse(read(component), component, ts.ScriptKind.TSX);
  const shellPath = "frontend/src/features/auth/components/member-shell.tsx";
  const source = read(shellPath);
  let next = source;
  if (!source.includes("<LogoutConfirmation")) {
    const file = parse(source, shellPath, ts.ScriptKind.TSX);
    const candidates = [];
    walk(file, node => {
      if (!ts.isJsxElement(node) || node.openingElement.tagName.getText(file) !== "Button") return;
      if (node.openingElement.attributes.properties.some(attribute => ts.isJsxAttribute(attribute) && attribute.name.getText(file) === "aria-label" && attribute.initializer && ts.isStringLiteral(attribute.initializer) && attribute.initializer.text === "Keluar dari akun")) candidates.push(node);
    });
    if (candidates.length !== 1 || !candidates[0].getText(file).includes(".logout()")) throw new Error("Tombol logout member-shell tidak sesuai pola yang dikenal. Tidak ada source yang diubah.");
    const button = candidates[0];
    next = source.slice(0, button.getStart(file)) + "<LogoutConfirmation />" + source.slice(button.end);
    next = next.replace(/import\s*\{([^}]+)\}\s*from\s*["']lucide-react["'];?/, (statement, names) => {
      const remaining = names.split(",").map(value => value.trim()).filter(value => value !== "LogOut");
      if (next.replace(statement, "").match(/\bLogOut\b/)) return statement;
      return remaining.length ? `import { ${remaining.join(", ")} } from "lucide-react";` : "";
    });
    if (!next.includes('from "./logout-confirmation"') && !next.includes("from './logout-confirmation'")) {
      const updated = parse(next, shellPath, ts.ScriptKind.TSX);
      const imports = updated.statements.filter(ts.isImportDeclaration);
      const last = imports[imports.length - 1];
      if (!last) throw new Error("Import member-shell tidak ditemukan.");
      next = next.slice(0, last.end) + '\nimport { LogoutConfirmation } from "./logout-confirmation";' + next.slice(last.end);
    }
    parse(next, shellPath, ts.ScriptKind.TSX);
    plan(shellPath, source, next);
  }
  const globalPath = "frontend/src/app/globals.css";
  const global = read(globalPath);
  const normalized = global.replace(/\r\n/g, "\n");
  if (normalized.includes("/* BEGIN: SRC stage07 logout confirmation */")) {
    if (!normalized.includes(css)) throw new Error("Blok CSS konfirmasi logout sudah dimodifikasi. Tidak ada source yang diubah.");
  } else {
    if (normalized.includes(".logout-confirmation-actions")) throw new Error("Selector CSS logout sudah tersedia tanpa penanda paket. Tidak ada source yang diubah.");
    const newline = global.includes("\r\n") ? "\r\n" : "\n";
    plan(globalPath, global, global + (global.endsWith(newline) ? newline : newline + newline) + css.replace(/\n/g, newline) + newline);
  }
  const browserDir = path.join(root, "frontend/tests/browser");
  for (const name of fs.readdirSync(browserDir).filter(name => name.endsWith(".spec.ts") && name !== "logout-confirmation.spec.ts")) patchBrowserTest(`frontend/tests/browser/${name}`);
  const notes = {
    "PRD.md": "Revisi tahap 7 — konfirmasi logout: klik tombol Keluar dari akun membuka dialog Ya, Keluar / Tidak. Hanya konfirmasi Ya, Keluar yang mengakhiri sesi. Tidak, Escape, atau menutup dialog mempertahankan sesi dan data anggota. Logout sukses menuju /login; penanganan kegagalan dan sesi berakhir tetap berlaku.",
    "ARCHITECTURE.md": "Revisi tahap 7 — konfirmasi logout: dialog merupakan state UI frontend. POST /api/v1/auth/logout dan pembersihan cache privat existing hanya dipanggil setelah Ya, Keluar. Pembatalan tidak mengirim request logout. Sesi berakhir atau penolakan autentikasi oleh server tetap ditangani guard tanpa dialog. Tidak ada perubahan endpoint/database; kegagalan logout tetap memakai pemulihan sesi existing.",
    "SYSTEM_DESIGN.md": "Revisi tahap 7 — konfirmasi logout: dialog berjudul Keluar dari akun? dengan teks Apakah Anda ingin logout dari sorerunapp?, tombol Tidak (outline, fokus awal) dan Ya, Keluar (primary Sunset). Escape/penutupan membatalkan dan mengembalikan fokus ke pemicu. Fokus terkurung di dialog; layout responsif 320 px, target tombol minimum 44 px. Styling terpusat di frontend/src/app/globals.css. Proses dan pemulihan logout memakai state existing."
  };
  for (const [relative, note] of Object.entries(notes)) {
    if (!fs.existsSync(path.join(root, relative))) { warnings.push(`Dokumen tidak tersedia: ${relative}`); continue; }
    const previous = read(relative);
    if (!previous.includes("Revisi tahap 7 — konfirmasi logout:")) plan(relative, previous, previous.trimEnd() + "\n\n" + note + "\n");
  }
  if (!edits.length) { console.log("Konfirmasi logout tahap 7 sudah diterapkan; tidak ada perubahan ulang."); process.exit(0); }
  const ignorePath = ".gitignore";
  const previousIgnore = fs.existsSync(path.join(root, ignorePath)) ? read(ignorePath) : "";
  if (!previousIgnore.split(/\r?\n/).some(line => [".auth-navigation-backup/", "/.auth-navigation-backup/"].includes(line.trim()))) plan(ignorePath, previousIgnore, previousIgnore.trimEnd() + "\n.auth-navigation-backup/\n");
  const backup = path.join(root, ".auth-navigation-backup", `stage07-logout-${Date.now()}`);
  for (const edit of edits) {
    const destination = path.join(backup, edit.relative);
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    fs.writeFileSync(destination, edit.previous);
  }
  for (const edit of edits) {
    fs.writeFileSync(path.join(root, edit.relative), edit.next);
    console.log(`Perbarui: ${edit.relative}`);
  }
  for (const warning of warnings) console.warn(warning);
  console.log(`Selesai — revisi tahap 7. Backup: ${path.relative(root, backup)}`);
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
