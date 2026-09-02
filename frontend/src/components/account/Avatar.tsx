export function Avatar({ name, url, size = 40 }: { name: string; url?: string | null; size?: number }) {
  if (url) {
    // eslint-disable-next-line @next/next/no-img-element -- avatars are user-uploaded, not a build-time-known asset set.
    return (
      <img
        src={url}
        alt={name}
        width={size}
        height={size}
        className="rounded-full border border-neutral-200 object-cover"
        style={{ width: size, height: size }}
      />
    );
  }

  return (
    <span
      className="flex shrink-0 items-center justify-center rounded-full bg-red-600 font-bold text-white"
      style={{ width: size, height: size, fontSize: size * 0.4 }}
    >
      {name.slice(0, 2).toUpperCase()}
    </span>
  );
}
