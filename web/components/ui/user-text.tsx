/**
 * Text a person typed (a note, a supplier's name, a reason, an address),
 * shown so it keeps its own direction (IV.21). An English note on an Arabic
 * page reads "before 9." and not ".before 9", and an Arabic name in an English
 * row stays right to left. A `<bdi>` takes its direction from its own first
 * strong letter and keeps the text around it unaffected.
 *
 * Use it wherever free text is shown inline; codes, quantities and amounts
 * use `dir="ltr"` instead, as they are always left to right.
 */

export function UserText({ children, className }: { children: React.ReactNode; className?: string }) {
  return <bdi className={className}>{children}</bdi>;
}
