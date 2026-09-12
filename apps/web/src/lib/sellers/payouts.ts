import type { PayoutField } from "@/lib/api/types";

/**
 * What to call each thing Stripe asks a seller for.
 *
 * The API sends `due` as a list of fields in its own words - one
 * `date_of_birth` rather than three of Stripe's dotted paths (ADR 0031) - and
 * says nothing about how to label them. That is this application's to write,
 * and `Record<PayoutField, ...>` keeps the list honest: a field the API adds is
 * a type error here until it has words, rather than a blank box on the page
 * somebody cannot get paid without filling in.
 *
 * Each key is also what the update endpoint takes, except `identity_document`,
 * which is a multipart endpoint of its own, and `terms`, which is a box to tick
 * rather than a value.
 */
export type PayoutFieldCopy = { label: string; hint?: string };

export const PAYOUT_FIELDS: Record<PayoutField, PayoutFieldCopy> = {
  first_name: { label: "First name", hint: "As it appears on your identity document." },
  last_name: { label: "Last name", hint: "As it appears on your identity document." },
  email: { label: "Email" },
  phone: { label: "Phone", hint: "With the country code, as +358 40 123 4567." },
  date_of_birth: { label: "Date of birth" },
  address: { label: "Home address", hint: "Where you live, which is not the shop's address." },
  id_number: {
    label: "ID number",
    hint: "Your national identity or tax number. It goes to Stripe and is not kept here.",
  },
  identity_document: {
    label: "Identity document",
    hint: "A passport, identity card or driving licence.",
  },
  iban: { label: "IBAN", hint: "The account the money is paid into." },
  terms: { label: "Stripe's terms" },
};

/**
 * The fields the details form can collect, in the order the API sent them.
 *
 * `identity_document` is a file and `terms` is a box, and each has its own
 * place on the page, so neither belongs among the text inputs.
 */
export function collectableFields(due: PayoutField[]): PayoutField[] {
  return due.filter((field) => field !== "identity_document" && field !== "terms");
}
