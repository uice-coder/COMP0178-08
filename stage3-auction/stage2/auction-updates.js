const auction = document.querySelector('#auction-update');

async function refreshAuction() {
    if (!auction || auction.dataset.status !== 'open') return;
    try {
        const response = await fetch(auction.dataset.refresh, {cache: 'no-store'});
        if (response.ok) {
            const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = documentFragment.querySelector('#auction-update');
            if (next && (next.innerHTML !== auction.innerHTML || next.dataset.status !== auction.dataset.status)) {
                const previousBid = auction.querySelector('#bid');
                const nextBid = next.querySelector('#bid');
                const restoreFocus = previousBid && document.activeElement === previousBid;
                if (previousBid && nextBid) nextBid.value = previousBid.value;
                auction.replaceChildren(...next.childNodes);
                auction.dataset.status = next.dataset.status;
                if (restoreFocus && nextBid) nextBid.focus();
            }
        }
    } catch (error) {
        // Retry temporary connection failures during the next update.
    }
    if (auction.dataset.status === 'open') window.setTimeout(refreshAuction, 5000);
}

if (auction?.dataset.status === 'open') window.setTimeout(refreshAuction, 5000);
