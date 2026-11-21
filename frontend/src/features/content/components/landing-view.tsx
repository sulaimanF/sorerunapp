import Link from "next/link";
import Image from "next/image";
import { Brand } from "@/components/shared/brand";
import { Button } from "@/components/ui/button";
import { visibleSections, type LandingDocument, type LandingImage, type LandingMedia } from "../schemas";
import { PrivateLandingImage } from "./private-landing-image";
import { ReloadLanding } from "./reload-landing";

export function LandingView({ content, media, privateMedia = false }: { content: LandingDocument; media: LandingMedia[]; privateMedia?: boolean }) {
  const sections = visibleSections(content);
  const Main = privateMedia ? "div" : "main";
  function picture(item: LandingImage | null, eager = false) {
    if (!item) return null;
    const file = media.find(file => file.id === item.media_id);
    if (!file) return <p className="supporting">Gambar belum tersedia.</p>;
    return <figure className="src-landing-image">
      {privateMedia ? <PrivateLandingImage key={file.id} id={file.id} alt={item.alt} width={file.width} height={file.height} /> :
        <Image src={file.url} width={file.width} height={file.height} alt={item.alt} sizes="(max-width: 639px) calc(100vw - 32px), (max-width: 1023px) 50vw, 600px" loading={eager ? "eager" : "lazy"} unoptimized />}
      {item.caption && <figcaption>{item.caption}</figcaption>}
    </figure>;
  }
  return <div className="src-landing">
    <header className="src-landing-header src-landing-container">
      <Brand />
      <nav aria-label="Navigasi komunitas" className="src-landing-desktop-nav">{sections.map(({ key }) => <a href={`#${key}`} key={key}>{content.header.nav_labels[key]}</a>)}</nav>
      <Button asChild><Link href="/login">{content.header.login_label}</Link></Button>
      {sections.length > 0 && <details className="src-landing-mobile-nav"><summary>Menu halaman</summary><nav aria-label="Navigasi section">{sections.map(({ key }) => <a href={`#${key}`} key={key}>{content.header.nav_labels[key]}</a>)}</nav></details>}
    </header>
    <Main id={privateMedia ? undefined : "main-content"}>
      <section className="src-landing-hero src-landing-container" aria-labelledby="landing-title">
        <div className="stack-medium">
          {content.hero.eyebrow && <p className="eyebrow text-brand">{content.hero.eyebrow}</p>}
          <h1 id="landing-title">{content.hero.title}</h1>
          <p className="src-landing-lead">{content.hero.description}</p>
          <div className="src-landing-actions"><Button asChild><Link href="/login">{content.hero.login_label}</Link></Button>
            {sections.some(section => section.key === "about") && <a className="text-link" href="#about">{content.header.nav_labels.about}</a>}
          </div>
        </div>
        {picture(content.hero.image, true)}
      </section>
      {sections.map(({ key }) => <section className={`src-landing-section src-landing-section-${key}`} id={key} aria-labelledby={`heading-${key}`} key={key}>
        <div className="src-landing-container stack-large">
          <h2 id={`heading-${key}`} className="text-heading-2">{content[key].title}</h2>
          {(key === "about" || key === "activities") && <>
            {content[key].description && <p className="src-landing-prose">{content[key].description}</p>}
            <div className="src-landing-grid">{content[key].items.map((item, index) => <article className="card stack-small" key={index}><h3 className="text-heading-3">{item.title}</h3><p>{item.description}</p></article>)}</div>
          </>}
          {key === "gallery" && <div className="src-landing-grid">{content.gallery.items.map((item, index) => <div key={index}>{picture(item)}</div>)}</div>}
          {key === "faq" && <div className="src-landing-faq">{content.faq.items.map((item, index) => <details key={index}><summary>{item.question}</summary><p>{item.answer}</p></details>)}</div>}
          {key === "contact" && <div className="stack-medium">
            {content.contact.description && <p className="src-landing-prose">{content.contact.description}</p>}
            {content.contact.email && <a className="text-link" href={`mailto:${content.contact.email}`}>{content.contact.email}</a>}
            {content.contact.phone && <a className="text-link" href={`tel:${content.contact.phone.replace(/[^+0-9]/g, "")}`}>{content.contact.phone}</a>}
            <div className="src-landing-actions">{content.contact.socials.map((item, index) => <a key={index} className="text-link" href={item.url} rel="noopener noreferrer" target="_blank">{item.label}<span className="sr-only"> (buka tab baru)</span></a>)}</div>
          </div>}
        </div>
      </section>)}
    </Main>
    <footer className="src-landing-footer"><div className="src-landing-container stack-medium"><Brand inverse />{content.footer.text && <p>{content.footer.text}</p>}<nav aria-label="Tautan informasi"><Link href="/privacy">Privasi</Link><Link href="/terms">Ketentuan</Link></nav></div></footer>
  </div>;
}

export function LandingUnavailable({ failed = false }: { failed?: boolean }) {
  return <div className="src-landing">
    <header className="src-landing-header src-landing-container"><Brand /><Button asChild><Link href="/login">Masuk ke Aplikasi</Link></Button></header>
    <main id="main-content" className="src-landing-empty src-landing-container stack-large">
      <p className="eyebrow text-brand">Sore Running Club</p>
      <h1 className="page-heading">{failed ? "Informasi komunitas belum dapat dimuat" : "Selamat datang di sorerunapp"}</h1>
      <p role={failed ? "alert" : undefined}>{failed ? "Layanan informasi sedang mengalami gangguan. Silakan muat ulang halaman untuk mencoba kembali." : "Informasi komunitas belum dipublikasikan. Anda tetap dapat membuka aplikasi."}</p>
      {failed && <ReloadLanding />}
      <Button asChild><Link href="/login">Masuk ke Aplikasi</Link></Button>
    </main>
  </div>;
}
