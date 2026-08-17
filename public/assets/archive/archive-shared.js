export { esc, publish, subscribe, PanicElement, $, $$ } from '../core.js';

export const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
export const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export function parseLocalDate(value) {
  const [year, month, day] = String(value).split('-').map(Number);
  return new Date(year, month - 1, day);
}

export function dateLabel(value, options = { year: 'numeric', month: 'long', day: 'numeric' }) {
  return new Intl.DateTimeFormat('en-US', options).format(parseLocalDate(value));
}

export function weekday(value, style = 'long') {
  return new Intl.DateTimeFormat('en-US', { weekday: style }).format(parseLocalDate(value));
}

export function number(value) {
  return new Intl.NumberFormat('en-US').format(value || 0);
}

export function plural(value, one, many = `${one}s`) {
  return `${number(value)} ${value === 1 ? one : many}`;
}

export function debounce(fn, wait = 220) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), wait);
  };
}

export function sourceName(path) {
  return String(path || '').split(/[\\/]/).pop() || 'Source file';
}

export function eraForYear(year, latestHistoricYear = 1987) {
  if (year <= latestHistoricYear) return 'Original era';
  if (year >= 2020) return 'Reopened era';
  return 'Later documentation';
}

export function downloadFile(filename, contents, type) {
  const blob = new Blob([contents], { type });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

export function csvCell(value) {
  const text = Array.isArray(value) ? value.join('; ') : String(value ?? '');
  return /[",\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

export function unique(values) {
  return [...new Set(values.filter(Boolean))];
}

export function focusButton(label, attributes = '') {
  return `<button type="button" class="archive-link" ${attributes}>${label}</button>`;
}
