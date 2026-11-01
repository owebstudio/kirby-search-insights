/**
 * Formatting and URL helpers shared by the components
 */
export const locale = () => window.panel.translation.code ?? "en";

export function number(value, digits = 0) {
  if (value === null || value === undefined) {
    return "–";
  }

  return new Intl.NumberFormat(locale(), {
    maximumFractionDigits: digits
  }).format(value);
}

export function percent(value, digits = 1) {
  if (value === null || value === undefined) {
    return "–";
  }

  return new Intl.NumberFormat(locale(), {
    style: "percent",
    maximumFractionDigits: digits
  }).format(value);
}

export function date(value, options = { day: "numeric", month: "short", year: "numeric" }) {
  if (!value) {
    return "–";
  }

  return new Intl.DateTimeFormat(locale(), { ...options, timeZone: "UTC" }).format(
    new Date(value + "T00:00:00Z")
  );
}

/**
 * Change against the previous period: "↑ 12 %", or `null` without a previous value
 */
export function change(current, previous) {
  if (!previous) {
    return null;
  }

  const value = current / previous - 1;

  if (Math.abs(value) < 0.005) {
    return "± " + percent(0, 0);
  }

  return (value > 0 ? "↑ " : "↓ ") + percent(Math.abs(value), 0);
}

/**
 * Base64url of a UTF-8 string, like `Panel::encode()` in PHP
 */
export function encode(value) {
  const bytes = new TextEncoder().encode(value);
  let binary = "";

  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/, "");
}

/**
 * Query of the current view without empty values
 */
export function query(values) {
  const result = {};

  for (const [key, value] of Object.entries(values)) {
    if (value !== null && value !== undefined && value !== "") {
      result[key] = String(value);
    }
  }

  return result;
}

export function rangeQuery(range) {
  return range.id === "custom" ? { range: "custom", from: range.from, to: range.to } : { range: range.id };
}
