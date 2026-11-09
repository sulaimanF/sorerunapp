"use strict";
// Merge the approved auth styles without replacing the user's global theme.
const fs = require("node:fs");
const path = require("node:path");
const root = path.resolve(__dirname, "..");
const css = ".src-auth-root > .src-auth-login-footer,\n.src-auth-root > .src-auth-secondary-links {\n  margin-top: 28px;\n}\n\n.src-auth-login-footer {\n  display: flex;\n  flex-direction: column;\n  align-items: center;\n  gap: 12px;\n  text-align: center;\n}\n\n.src-auth-login-footer > * {\n  margin: 0;\n}\n\n.src-auth-register-line {\n  display: flex;\n  flex-wrap: wrap;\n  align-items: center;\n  justify-content: center;\n  column-gap: 4px;\n}\n\n.src-auth-secondary-links {\n  display: flex;\n  flex-direction: column;\n  align-items: flex-start;\n  gap: 12px;\n}\n\n.src-auth-secondary-links > * {\n  margin: 0;\n}\n\n@media (pointer: coarse) {\n  .src-auth-login-footer a,\n  .src-auth-secondary-links a {\n    display: inline-flex;\n    align-items: center;\n    min-height: 44px;\n  }\n}\n";
const begin = "/* BEGIN: SRC auth layout */";
const end = "/* END: SRC auth layout */";
const block = `${begin}\n${css.trim()}\n${end}`;
const edits = [];
const normalize = value => value.replace(/\r\n/g, "\n");
function read(relative) {
  const filename = path.join(root, relative);
  if (!fs.existsSync(filename)) throw new Error(`File belum tersedia: ${relative}. Terapkan paket navigasi auth sebelumnya terlebih dahulu.`);
  return fs.readFileSync(filename, "utf8");
}
function plan(relative, previous, next) {
  if (previous !== next) edits.push({ relative, previous, next });
}
try {
  const globalPath = "frontend/src/app/globals.css";
  const global = read(globalPath);
  const normalized = normalize(global);
  if (normalized.includes(begin)) {
    if (!normalized.includes(block) || normalized.split(begin).length !== 2 || normalized.split(end).length !== 2) {
      throw new Error("Blok styling auth di globals.css berbeda. Tidak ada file yang diubah; periksa perubahan lokal terlebih dahulu.");
    }
  } else {
    if (normalized.includes(end) || /\.src-auth-/.test(normalized)) {
      throw new Error("globals.css sudah memiliki styling src-auth- tanpa penanda paket. Tidak ada file yang diubah; periksa agar tidak terjadi duplikasi.");
    }
    const newline = global.includes("\r\n") ? "\r\n" : "\n";
    plan(globalPath, global, global + (global.endsWith(newline) ? newline : newline + newline) + block.replace(/\n/g, newline) + newline);
  }
  const oldPath = "frontend/src/features/auth/components/auth-layout.css";
  if (fs.existsSync(path.join(root, oldPath))) {
    const old = read(oldPath);
    const body = normalize(old).replace(/^\/\*[^]*?\*\/\s*/, "").trim();
    if (body !== css.trim()) throw new Error("auth-layout.css memiliki perubahan tambahan. Tidak ada file yang diubah; pindahkan perubahan tersebut secara manual agar tidak hilang.");
    plan(oldPath, old, null);
  }
  for (const name of ["login-form", "verification-screen", "resend-verification-form"]) {
    const relative = `frontend/src/features/auth/components/${name}.tsx`;
    const previous = read(relative);
    if (!previous.includes("src-auth-root")) throw new Error(`Komponen ${name} belum menggunakan layout auth yang disetujui. Terapkan paket navigasi sebelumnya dahulu.`);
    const next = previous.replace(/^[ \t]*import ["']\.\/auth-layout\.css["'];?[ \t]*\r?\n/gm, "");
    plan(relative, previous, next);
  }
  const designPath = "SYSTEM_DESIGN.md";
  if (fs.existsSync(path.join(root, designPath))) {
    const previous = read(designPath);
    plan(designPath, previous, previous.replace("Pakai stylesheet khusus auth dengan selector berprefiks src-auth- untuk spacing dan layout, sesuai pipeline CSS Turbopack existing;", "Pusatkan styling auth di frontend/src/app/globals.css dengan selector berprefiks src-auth- untuk spacing dan layout; tidak menggunakan file auth-layout.css atau import CSS pada komponen auth;"));
  }
  if (!edits.length) {
    console.log("Styling auth sudah terpusat di globals.css. Tidak ada perubahan ulang.");
    process.exit(0);
  }
  const ignorePath = ".gitignore";
  const previousIgnore = fs.existsSync(path.join(root, ignorePath)) ? read(ignorePath) : "";
  if (!previousIgnore.split(/\r?\n/).some(line => line.trim() === ".auth-navigation-backup/" || line.trim() === "/.auth-navigation-backup/")) {
    plan(ignorePath, previousIgnore, previousIgnore + (previousIgnore.endsWith("\n") ? "" : "\n") + ".auth-navigation-backup/\n");
  }
  const backup = path.join(root, ".auth-navigation-backup", `centralize-css-${Date.now()}`);
  // Complete all validation and backups before writing shared source files.
  for (const edit of edits) {
    const destination = path.join(backup, edit.relative);
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    fs.writeFileSync(destination, edit.previous);
  }
  for (const edit of edits) {
    const destination = path.join(root, edit.relative);
    if (edit.next === null) fs.unlinkSync(destination);
    else fs.writeFileSync(destination, edit.next);
    console.log(`${edit.next === null ? "Hapus" : "Perbarui"}: ${edit.relative}`);
  }
  console.log("Selesai. Styling auth dimuat dari globals.css melalui root layout.");
  console.log(`Backup: ${path.relative(root, backup)}`);
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
