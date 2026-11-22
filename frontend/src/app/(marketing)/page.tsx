import { Suspense } from "react";
import { LoadingPanel } from "@/components/shared/feedback";
import type { Metadata } from "next";
import { unstable_rethrow } from "next/navigation";
import { readPublicLanding, publicSiteOrigin, landingIndexable } from "@/features/content/public-content";
import { LandingUnavailable, LandingView } from "@/features/content/components/landing-view";

export async function generateMetadata(): Promise<Metadata> {
  try {
    const publication = await readPublicLanding();
    const origin = publicSiteOrigin();
    const index = publication !== null && landingIndexable();
    const title = publication?.content.seo.title || "Sore Running Club — sorerunapp";
    const description = publication?.content.seo.description || "Informasi komunitas Sore Running Club dan akses aplikasi sorerunapp.";
    const socialImageId = publication?.content.seo.image?.media_id;
    const socialImage = publication?.media.find(item => item.id === socialImageId);
    return {
      title: { absolute: title }, description, alternates: { canonical: `${origin}/` },
      robots: { index, follow: index },
      openGraph: { title, description, url: `${origin}/`, type: "website", locale: "id_ID", siteName: "Sore Running Club", ...(socialImage ? { images: [{ url: socialImage.url, width: socialImage.width, height: socialImage.height, alt: publication?.content.seo.image?.alt }] } : {}) },
    };
  } catch (error) {
    // Preserve Next.js request-time/Suspense control flow; only render API failures here.
    unstable_rethrow(error);
    return { title: { absolute: "Sore Running Club — sorerunapp" }, robots: { index: false, follow: false } };
  }
}

async function PublishedLanding() {
  let publication;
  try {
    publication = await readPublicLanding();
  } catch (error) {
    unstable_rethrow(error);
    return <LandingUnavailable failed />;
  }
  return publication ? <LandingView content={publication.content} media={publication.media} /> : <LandingUnavailable />;
}

export default function Page() {
  // Uncached API work stays inside Suspense with Cache Components enabled.
  return <Suspense fallback={<main id="main-content" className="src-landing-loading"><LoadingPanel>Memuat informasi komunitas…</LoadingPanel></main>}><PublishedLanding /></Suspense>;
}
