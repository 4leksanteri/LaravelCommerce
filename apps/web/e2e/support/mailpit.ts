/**
 * Reading the development inbox, the way a person would: find the message sent
 * to this address and follow the link in it.
 *
 * Found by recipient rather than by clearing the inbox first. Wiping Mailpit
 * would destroy whatever else somebody had open in it, and every test uses a
 * fresh address anyway.
 */
const MAILPIT = `http://localhost:${process.env.MAILPIT_UI_PORT ?? "8025"}`;

type Search = { messages: Array<{ ID: string; Subject: string }> };
type Message = { HTML: string; Text: string };

/**
 * The path and query of the first link in the newest message to `to` whose URL
 * contains `path`. Only the path is returned, so the test follows it on its own
 * `baseURL` whatever host FRONTEND_URL happened to put in the email.
 */
export async function linkFromInbox(
  to: string,
  path: "/verify-email" | "/reset-password",
): Promise<string> {
  const deadline = Date.now() + 15_000;

  while (Date.now() < deadline) {
    const search = (await (
      await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}&limit=10`)
    ).json()) as Search;

    for (const summary of search.messages) {
      const message = (await (
        await fetch(`${MAILPIT}/api/v1/message/${summary.ID}`)
      ).json()) as Message;
      const found = `${message.Text}\n${message.HTML}`.match(
        new RegExp(`https?://[^\\s"<>]*${path}\\?[^\\s"<>]*`),
      );

      if (found) {
        const url = new URL(found[0].replaceAll("&amp;", "&"));

        return `${url.pathname}${url.search}`;
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 250));
  }

  throw new Error(`No email to ${to} containing a ${path} link arrived within 15 seconds.`);
}
