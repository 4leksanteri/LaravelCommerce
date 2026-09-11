/**
 * An address, as lines on an envelope.
 *
 * Takes the shape an address-book entry and an order's frozen copy have in
 * common, so the same component draws both - but they stay different types,
 * because one is editable and the other must never be (ADR 0021).
 *
 * The optional parts are simply left out when empty. Plenty of countries have
 * no region worth writing and some have no postal code at all, and a blank line
 * on a label is worse than a shorter label.
 */
type AddressShape = {
  name: string | null;
  line1: string | null;
  line2: string | null;
  city: string | null;
  region: string | null;
  postal_code: string | null;
  country: string | null;
};

export function AddressLines({ address }: { address: AddressShape }) {
  const locality = [address.postal_code, address.city].filter(Boolean).join(" ");

  return (
    <span className="block text-sm leading-relaxed">
      {address.name ? <span className="block font-medium">{address.name}</span> : null}
      {address.line1 ? <span className="block">{address.line1}</span> : null}
      {address.line2 ? <span className="block">{address.line2}</span> : null}
      {locality ? <span className="block">{locality}</span> : null}
      {address.region ? <span className="block">{address.region}</span> : null}
      {address.country ? <span className="block">{address.country}</span> : null}
    </span>
  );
}
