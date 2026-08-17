import { MONTHS_LONG, WEEKDAYS, eraForYear, parseLocalDate, unique } from './archive-shared.js';

/**
 * Client-side archive index. All expensive normalization happens once during
 * load so components can explore derived structures instead of reparsing JSON.
 */
export class ArchiveStore {
  constructor(url = './data/mab-shows.json') {
    this.url = url;
    this.records = [];
    this.showNights = [];
    this.performers = new Map();
    this.recordsByDate = new Map();
    this.recordsByYear = new Map();
    this.recordsByPerformer = new Map();
    this.nightsByYear = new Map();
  }

  async load() {
    const response = await fetch(this.url, { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`HTTP ${response.status} ${response.statusText}`);
    const raw = await response.json();
    if (!raw || !Array.isArray(raw.concerts)) throw new Error('The archive file does not contain a concerts[] array.');
    this.metadata = raw;
    this.records = raw.concerts.map((record, index) => this.#normalizeRecord(record, index));
    this.#buildIndexes();
    return this;
  }

  #normalizeRecord(original, index) {
    const date = String(original.date || '');
    const parsed = parseLocalDate(date);
    const performers = unique((original.performers || []).map((performer) => performer?.name?.trim()));
    return {
      id: `record-${index + 1}`,
      index,
      date,
      year: parsed.getFullYear(),
      month: parsed.getMonth(),
      weekday: parsed.getDay(),
      name: original.name || performers.join(' / ') || 'Untitled record',
      venue: original.venue || {},
      url: original.url || '',
      performers,
      sourceFiles: unique(original.source_files || []),
      explicitBill: performers.length > 1,
      original,
    };
  }

  #buildIndexes() {
    for (const record of this.records) {
      this.#push(this.recordsByDate, record.date, record);
      this.#push(this.recordsByYear, record.year, record);
      for (const name of record.performers) this.#push(this.recordsByPerformer, name, record);
    }

    this.showNights = [...this.recordsByDate.entries()].map(([date, records]) => {
      const performers = unique(records.flatMap((record) => record.performers)).sort((a, b) => a.localeCompare(b));
      const explicitBills = records.filter((record) => record.explicitBill);
      return {
        date,
        year: records[0].year,
        month: records[0].month,
        weekday: records[0].weekday,
        records,
        performers,
        explicitBills,
        recordCount: records.length,
        performerCount: performers.length,
        hasSeparateRecords: records.length > 1,
        era: eraForYear(records[0].year),
      };
    }).sort((a, b) => a.date.localeCompare(b.date));

    this.nightsByDate = new Map(this.showNights.map((night) => [night.date, night]));
    for (const night of this.showNights) this.#push(this.nightsByYear, night.year, night);
    this.#buildPerformerStats();

    const years = this.showNights.map((night) => night.year);
    this.minYear = Math.min(...years);
    this.maxYear = Math.max(...years);
    this.activeYears = [...new Set(years)].sort((a, b) => a - b);
    this.decades = [...new Set(this.activeYears.map((year) => Math.floor(year / 10) * 10))];
  }

  #buildPerformerStats() {
    for (const [name, records] of this.recordsByPerformer) {
      const dates = [...new Set(records.map((record) => record.date))].sort();
      const years = [...new Set(records.map((record) => record.year))].sort((a, b) => a - b);
      const related = new Map();

      for (const date of dates) {
        const night = this.nightsByDate.get(date);
        for (const other of night.performers) {
          if (other === name) continue;
          const relationship = related.get(other) || { name: other, explicitBillCount: 0, sameNightCount: 0 };
          relationship.sameNightCount += 1;
          const explicitOnDate = night.explicitBills.filter((record) => record.performers.includes(name) && record.performers.includes(other)).length;
          relationship.explicitBillCount += explicitOnDate;
          related.set(other, relationship);
        }
      }

      const firstAppearance = dates[0];
      const lastAppearance = dates.at(-1);
      const spanYears = Math.max(0, parseLocalDate(lastAppearance).getFullYear() - parseLocalDate(firstAppearance).getFullYear());
      this.performers.set(name, {
        name,
        appearances: records.length,
        records,
        dates,
        nights: dates.map((date) => this.nightsByDate.get(date)),
        uniqueNights: dates.length,
        firstAppearance,
        lastAppearance,
        yearsActiveAtMab: years,
        spanYears,
        explicitBills: records.filter((record) => record.explicitBill),
        sameNightArtists: [...related.values()].sort((a, b) => b.sameNightCount - a.sameNightCount || b.explicitBillCount - a.explicitBillCount || a.name.localeCompare(b.name)),
      });
    }
  }

  #push(map, key, value) {
    const items = map.get(key) || [];
    items.push(value);
    map.set(key, items);
  }

  getShowNight(date) { return this.nightsByDate.get(date); }
  getPerformer(name) { return this.performers.get(name); }
  getRelatedPerformers(name) { return this.performers.get(name)?.sameNightArtists || []; }

  defaultCriteria() {
    return {
      minYear: this.minYear,
      maxYear: this.maxYear,
      month: null,
      search: '',
      performers: [],
      complexity: 'all',
      recordType: 'all',
      weekday: '',
    };
  }

  filter(criteria = {}) {
    const selectedPerformers = new Set(criteria.performers || []);
    const query = String(criteria.search || '').trim().toLocaleLowerCase();
    const minYear = Number(criteria.minYear ?? this.minYear);
    const maxYear = Number(criteria.maxYear ?? this.maxYear);

    const nights = this.showNights.filter((night) => {
      if (night.year < minYear || night.year > maxYear) return false;
      if (criteria.month !== null && criteria.month !== undefined && Number(criteria.month) !== night.month) return false;
      if (criteria.weekday !== '' && Number(criteria.weekday) !== night.weekday) return false;
      if (selectedPerformers.size && ![...selectedPerformers].every((name) => night.performers.includes(name))) return false;
      if (query) {
        const haystack = [night.date, ...night.performers, ...night.records.map((record) => record.name), ...night.records.map((record) => record.venue.name), ...night.records.map((record) => record.venue.address)].join(' ').toLocaleLowerCase();
        if (!haystack.includes(query)) return false;
      }
      if (criteria.complexity === 'one' && night.performerCount !== 1) return false;
      if (criteria.complexity === '2plus' && night.performerCount < 2) return false;
      if (criteria.complexity === '3plus' && night.performerCount < 3) return false;
      if (criteria.complexity === 'explicit' && !night.explicitBills.length) return false;
      if (criteria.complexity === 'multi-record' && night.recordCount < 2) return false;
      if (criteria.recordType === 'explicit' && !night.explicitBills.length) return false;
      if (criteria.recordType === 'single' && !night.records.some((record) => !record.explicitBill)) return false;
      if (criteria.recordType === 'multi-date' && night.recordCount < 2) return false;
      return true;
    });

    let records = nights.flatMap((night) => night.records);
    if (criteria.recordType === 'explicit') records = records.filter((record) => record.explicitBill);
    if (criteria.recordType === 'single') records = records.filter((record) => !record.explicitBill);
    return { nights, records };
  }

  summarize(nights = this.showNights, records = this.records) {
    const performers = new Set(nights.flatMap((night) => night.performers));
    const years = [...new Set(nights.map((night) => night.year))];
    const explicit = records.filter((record) => record.explicitBill).length;
    const repeatPerformers = [...performers].filter((name) => {
      const dates = new Set(records.filter((record) => record.performers.includes(name)).map((record) => record.date));
      return dates.size > 1;
    }).length;
    const busiestYear = years.map((year) => ({ year, count: records.filter((record) => record.year === year).length })).sort((a, b) => b.count - a.count)[0];
    return {
      records: records.length,
      nights: nights.length,
      performers: performers.size,
      years: years.length,
      explicit,
      oneTimePerformers: performers.size - repeatPerformers,
      repeatPerformers,
      averageRecordsPerNight: nights.length ? records.length / nights.length : 0,
      busiestYear,
    };
  }

  aggregate(nights, unit = 'year') {
    const map = new Map();
    const add = (key, label, sort, night) => {
      const item = map.get(key) || { key, label, sort, records: 0, nights: 0, performers: new Set(), dates: new Set() };
      item.records += night.recordCount;
      item.nights += 1;
      item.dates.add(night.date);
      night.performers.forEach((name) => item.performers.add(name));
      map.set(key, item);
    };

    for (const night of nights) {
      if (unit === 'decade') {
        const decade = Math.floor(night.year / 10) * 10;
        add(String(decade), `${decade}s`, decade, night);
      } else if (unit === 'month') {
        const month = `${night.year}-${String(night.month + 1).padStart(2, '0')}`;
        add(month, `${MONTHS_LONG[night.month]} ${night.year}`, month, night);
      } else {
        add(String(night.year), String(night.year), night.year, night);
      }
    }

    // Honest chronology: preserve empty periods inside the selected range.
    if (nights.length && unit === 'year') {
      const min = Math.min(...nights.map((night) => night.year));
      const max = Math.max(...nights.map((night) => night.year));
      for (let year = min; year <= max; year += 1) {
        if (!map.has(String(year))) map.set(String(year), { key: String(year), label: String(year), sort: year, records: 0, nights: 0, performers: new Set(), dates: new Set() });
      }
    }
    if (nights.length && unit === 'month') {
      const start = Math.min(...nights.map((night) => night.year * 12 + night.month));
      const end = Math.max(...nights.map((night) => night.year * 12 + night.month));
      for (let token = start; token <= end; token += 1) {
        const year = Math.floor(token / 12);
        const month = token % 12;
        const key = `${year}-${String(month + 1).padStart(2, '0')}`;
        if (!map.has(key)) map.set(key, { key, label: `${MONTHS_LONG[month]} ${year}`, sort: key, records: 0, nights: 0, performers: new Set(), dates: new Set() });
      }
    }
    return [...map.values()].sort((a, b) => a.sort > b.sort ? 1 : -1).map((item) => ({ ...item, performerCount: item.performers.size }));
  }

  monthMatrix(nights) {
    const years = [...new Set(nights.map((night) => night.year))].sort((a, b) => a - b);
    const cells = new Map();
    for (const night of nights) {
      const key = `${night.year}-${night.month}`;
      const cell = cells.get(key) || { records: 0, nights: 0, performers: new Set() };
      cell.records += night.recordCount;
      cell.nights += 1;
      night.performers.forEach((name) => cell.performers.add(name));
      cells.set(key, cell);
    }
    return { years, cells };
  }

  performerRanking(nights, metric = 'appearances') {
    const allowedDates = new Set(nights.map((night) => night.date));
    return [...this.performers.values()].map((stats) => {
      const filteredNights = stats.nights.filter((night) => allowedDates.has(night.date));
      const filteredRecords = stats.records.filter((record) => allowedDates.has(record.date));
      const years = [...new Set(filteredNights.map((night) => night.year))];
      const first = filteredNights[0]?.date;
      const last = filteredNights.at(-1)?.date;
      const value = metric === 'nights' ? filteredNights.length
        : metric === 'years' ? years.length
          : metric === 'span' && first && last ? parseLocalDate(last).getFullYear() - parseLocalDate(first).getFullYear()
            : filteredRecords.length;
      return { ...stats, value, filteredNights, filteredRecords };
    }).filter((item) => item.value > 0).sort((a, b) => b.value - a.value || b.appearances - a.appearances || a.name.localeCompare(b.name));
  }

  weekdayStats(nights) {
    return WEEKDAYS.map((label, day) => ({ day, label, count: nights.filter((night) => night.weekday === day).length }));
  }
}

export const archiveStore = new ArchiveStore();
