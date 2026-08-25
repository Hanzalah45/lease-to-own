export function PlaceholderSection({ title, milestone }: { title: string; milestone: string }) {
  return (
    <div>
      <h1 className="text-2xl font-bold uppercase tracking-tight">{title}</h1>
      <p className="mt-2 max-w-prose text-sm text-neutral-500">
        Structure only for now — wired up to the API in {milestone}.
      </p>
    </div>
  );
}
