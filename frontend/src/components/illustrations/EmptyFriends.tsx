/** Empty-state illustration — no friends added yet. */
export function EmptyFriends({ className }: { className?: string }) {
  return (
    <svg className={className} width="140" height="100" viewBox="0 0 140 100" fill="none" role="img" aria-label="Two empty seats waiting for friends">
      <rect x="14" y="46" width="46" height="40" rx="14" fill="#ECE3FE" />
      <circle cx="37" cy="30" r="16" fill="#8B5CF6" />
      <rect x="80" y="52" width="46" height="34" rx="14" fill="#FFE4EA" />
      <circle cx="103" cy="38" r="14" fill="#FF5C7A" />
      <path d="M60 66h20" stroke="#FFC93C" strokeWidth="4" strokeLinecap="round" strokeDasharray="1 10" />
      <circle cx="16" cy="18" r="4" fill="#17C3B2" />
      <circle cx="128" cy="26" r="3.5" fill="#FFC93C" />
    </svg>
  );
}
