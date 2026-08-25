import { useEffect, useRef, type RefObject } from "react";

/** Closes a dropdown/menu when the user clicks anywhere outside the returned ref. */
export function useClickOutside<T extends HTMLElement>(onOutsideClick: () => void): RefObject<T | null> {
  const ref = useRef<T | null>(null);

  useEffect(() => {
    function handleClick(event: MouseEvent) {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        onOutsideClick();
      }
    }

    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, [onOutsideClick]);

  return ref;
}
