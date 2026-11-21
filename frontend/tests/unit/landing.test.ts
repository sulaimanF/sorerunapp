import test from "node:test";
import assert from "node:assert/strict";
import { landingDocumentSchema, landingPublicationSchema, visibleSections } from "../../src/features/content/schemas";
import { landingFixtureContent } from "../support/landing-content";

test("optional empty and hidden sections have no navigation", () => {
  const content = landingFixtureContent();
  assert.deepEqual(visibleSections(content).map(item => item.key), ["about", "faq"]);
  content.sections[0].visible = false;
  assert.deepEqual(visibleSections(content).map(item => item.key), ["faq"]);
});
test("content cannot change login destination or contain script markup", () => {
  const content = landingFixtureContent();
  assert.equal(landingDocumentSchema.safeParse({ ...content, header: { ...content.header, login_url: "https://external.example" } }).success, false);
  assert.equal(landingDocumentSchema.safeParse({ ...content, hero: { ...content.hero, title: "<script>alert(1)</script>" } }).success, false);
});
test("dangerous URLs, URL credentials and duplicate sections are invalid", () => {
  for (const url of ["bukan-url", "javascript:alert(1)", "data:text/html,test", "https://user:pass@example.test/"]) {
    const content = landingFixtureContent(); content.contact.socials.push({ label: "Test", url });
    assert.equal(landingDocumentSchema.safeParse(content).success, false);
  }
  const content = landingFixtureContent(); content.sections[1] = { ...content.sections[0] };
  assert.equal(landingDocumentSchema.safeParse(content).success, false);
});
test("no-publication receipt is distinct from corrupt or draft payload", () => {
  assert.deepEqual(landingPublicationSchema.parse({ publication: null }), { publication: null });
  assert.equal(landingPublicationSchema.safeParse({ draft: landingFixtureContent() }).success, false);
  assert.equal(landingPublicationSchema.safeParse({ publication: { content: landingFixtureContent() } }).success, false);
});
