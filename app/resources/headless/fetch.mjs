// Headless fetch script for Yandex Maps (fallback strategy).
// Invoked as: node fetch.mjs <organization-url> <business-id>
// Expected output (stdout): a single JSON object with the same schema the
// JSON strategy consumes: { reviews, rating, ratingsCount, reviewsCount, csrfToken }.

const url = process.argv[2];

if (!url) {
    console.error('usage: node fetch.mjs <url> <business-id>');
    process.exit(2);
}

try {
    const { chromium } = await import('playwright');

    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();

    const responses = [];
    page.on('response', (response) => {
        if (response.url().includes('/maps/api/business/fetchReviews')) {
            responses.push(response.json().catch(() => null));
        }
    });

    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

    for (let i = 0; i < 5; i += 1) {
        await page.mouse.wheel(0, 3000);
        await page.waitForTimeout(800);
    }

    const payloads = (await Promise.all(responses)).filter(Boolean);

    await browser.close();

    const reviews = payloads.flatMap((payload) => payload.data?.reviews ?? payload.reviews ?? []);
    const aggregate = payloads[0]?.data?.params ?? payloads[0] ?? { rating: null, ratingsCount: 0, reviewsCount: 0 };

    process.stdout.write(JSON.stringify({
        reviews,
        rating: aggregate.rating ?? null,
        ratingsCount: aggregate.ratingsCount ?? 0,
        reviewsCount: aggregate.reviewsCount ?? aggregate.count ?? reviews.length,
        csrfToken: 'headless',
    }));
} catch (error) {
    console.error(error.message ?? String(error));
    process.exit(1);
}
