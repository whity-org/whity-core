/**
 * Colour by (design 3.5, CV.4): each person chooses whether colours mean the
 * person, the service or the room or branch, for themselves only. Colours come
 * from the 2.7 chart series in order (`--chart-1` …), in each list's own
 * order, so the checkbox and the booking always share one colour. Colour is
 * never the only signal: the name is always written on the booking.
 */

export type ColourBy = 'person' | 'service' | 'room';

export const COLOUR_BY: readonly ColourBy[] = ['person', 'service', 'room'];

/** How many colours the series has before it starts again. */
const SERIES = 6;

/** The nth colour of the series, as a CSS value. */
export function seriesColour(index: number): string {
  return `var(--chart-${(((index % SERIES) + SERIES) % SERIES) + 1})`;
}

/** Grey: team events and anything with no colour of its own. */
export const NEUTRAL = 'var(--muted-foreground)';

export interface Palette {
  person: Map<number, string>;
  service: Map<number, string>;
  room: Map<number, string>;
}

/** Colours for every person, service and room, in the order they are listed. */
export function palette(people: readonly number[], services: readonly number[], rooms: readonly number[]): Palette {
  const of = (ids: readonly number[]) => new Map(ids.map((id, i) => [id, seriesColour(i)]));
  return { person: of(people), service: of(services), room: of(rooms) };
}

/** The colour of something on the calendar under the person's Colour by. */
export function colourOf(
  by: ColourBy,
  colours: Palette,
  thing: { profileId?: number | null; serviceId?: number | null; roomId?: number | null }
): string {
  const id = by === 'person' ? thing.profileId : by === 'service' ? thing.serviceId : thing.roomId;
  if (id === null || id === undefined) return NEUTRAL;
  return colours[by].get(id) ?? NEUTRAL;
}
