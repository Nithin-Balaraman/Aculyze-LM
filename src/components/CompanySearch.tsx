"use client";

import { useEffect, useRef, useState } from "react";
import type { CompanySearchResult } from "@/lib/queries";

type CompanySearchProps = {
  placeholder?: string;
  onSelect: (result: CompanySearchResult) => void;
  autoFocus?: boolean;
  className?: string;
};

// Real type-ahead: queries /api/leads/search as you type (debounced) and
// shows a dropdown of matches. Used both by the global nav search and by
// the "log an activity" form's company picker.
export function CompanySearch({
  placeholder = "Search companies...",
  onSelect,
  autoFocus,
  className,
}: CompanySearchProps) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<CompanySearchResult[]>([]);
  const [open, setOpen] = useState(false);
  const [highlighted, setHighlighted] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    // Nothing to search — leave any stale results/open state alone rather
    // than setState-ing synchronously here; the render below already only
    // shows the dropdown when the query is non-empty.
    if (query.trim().length === 0) return;

    const controller = new AbortController();
    const timer = setTimeout(async () => {
      try {
        const res = await fetch(`/api/leads/search?q=${encodeURIComponent(query)}`, {
          signal: controller.signal,
        });
        if (!res.ok) return;
        const data: { results: CompanySearchResult[] } = await res.json();
        setResults(data.results);
        setOpen(true);
        setHighlighted(0);
      } catch {
        // aborted or network hiccup — a later keystroke will retry
      }
    }, 200);

    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [query]);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  function select(result: CompanySearchResult) {
    onSelect(result);
    setQuery(result.companyName);
    setOpen(false);
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (!open || results.length === 0) return;
    if (event.key === "ArrowDown") {
      event.preventDefault();
      setHighlighted((i) => Math.min(i + 1, results.length - 1));
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      setHighlighted((i) => Math.max(i - 1, 0));
    } else if (event.key === "Enter") {
      event.preventDefault();
      select(results[highlighted]);
    } else if (event.key === "Escape") {
      setOpen(false);
    }
  }

  return (
    <div ref={containerRef} className={`relative ${className ?? ""}`}>
      <input
        type="text"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        onFocus={() => results.length > 0 && setOpen(true)}
        onKeyDown={handleKeyDown}
        placeholder={placeholder}
        autoFocus={autoFocus}
        className="w-full rounded-md border border-slate-300 px-3 py-2 text-base focus:border-slate-500 focus:outline-none"
      />
      {open && query.trim().length > 0 && results.length > 0 && (
        <ul className="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg">
          {results.map((result, i) => (
            <li key={result.id}>
              <button
                type="button"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => select(result)}
                className={`block w-full px-3 py-2 text-left text-sm ${
                  i === highlighted ? "bg-slate-100" : "bg-white"
                } hover:bg-slate-100`}
              >
                <div className="font-medium text-slate-900">{result.companyName}</div>
                <div className="text-xs text-slate-500">
                  {result.leadId}
                  {result.city ? ` · ${result.city}` : ""}
                  {result.industry ? ` · ${result.industry}` : ""}
                </div>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
