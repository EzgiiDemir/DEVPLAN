import { CompanionProvider } from "@/lib/companion-context";

export default function StudioLayout({ children }) {
  return <CompanionProvider>{children}</CompanionProvider>;
}
