interface EmptyStateIconProps {
  className?: string;
}

export const EmptyStateIcon = ({ className = 'h-8 w-8 text-slate-300' }: EmptyStateIconProps) => {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.5"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <rect x="3" y="3" width="18" height="14" rx="2" />
      <path d="M3 9h18" />
      <path d="M7 15h2" />
    </svg>
  );
};
