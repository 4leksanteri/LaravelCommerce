import type { CategoryTree } from "@/lib/api/types";

/**
 * One line of a category menu: what to send, what to show, and how deep it sits.
 *
 * The API sends categories as a tree, which is right for navigation and wrong
 * for a `<select>`: a native menu is a flat list of options, and the nesting has
 * to survive as indentation instead (ADR 0038).
 *
 * Every category is offered, parents included. Which of them a listing may sit
 * in is the API's rule - it accepts any category that exists - and a menu that
 * offered only the leaves would be this application inventing one.
 */
export type CategoryChoice = { id: number; name: string; depth: number };

export function categoryChoices(tree: CategoryTree, depth = 0): CategoryChoice[] {
  return tree.flatMap((category) => [
    { id: category.id, name: category.name, depth },
    ...categoryChoices(category.children ?? [], depth + 1),
  ]);
}
