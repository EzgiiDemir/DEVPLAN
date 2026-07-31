// lucide-react doesn't ship OS/brand icons — a small inline mark, same
// convention as GithubIcon.
export function WindowsIcon({ size = 16, className }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden="true">
      <path d="M3 5.5 10.4 4.5V11.3H3V5.5ZM11.3 4.4 21 3V11.2H11.3V4.4ZM3 12.2H10.4V19L3 18V12.2ZM11.3 12.2H21V20.9L11.3 19.6V12.2Z" />
    </svg>
  );
}
