jQuery(document).ready(function ($) {
    let currentPage = 1;
    let currentQuery = '';
    let currentOrientation = '';
    let currentMinWidth = '';
    let currentMinHeight = '';

    // Handle search form submission
    $('#wcm-search-form').on('submit', function (e) {
        e.preventDefault();
        currentQuery = $('#wcm-search-query').val().trim();
        currentOrientation = $('#wcm-orientation').val();
        currentMinWidth = $('#wcm-min-width').val();
        currentMinHeight = $('#wcm-min-height').val();

        if (currentQuery === '') {
            alert('Please enter a search query.');
            return;
        }

        currentPage = 1;
        searchImages();
    });

    // Function to search images
    function searchImages() {
        $('#wcm-results').html('<div class="wcm-loading"></div>');
        $.ajax({
            url: wcm_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'wcm_search_commons',
                query: currentQuery,
                page: currentPage,
                orientation: currentOrientation,
                min_width: currentMinWidth,
                min_height: currentMinHeight,
                nonce: wcm_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    displayResults(response.data);
                } else {
                    $('#wcm-results').html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                }
            },
            error: function () {
                $('#wcm-results').html('<div class="notice notice-error"><p>An unexpected error occurred.</p></div>');
            }
        });
    }

    // Function to display search results
    function displayResults(data) {
        if (data.hits.length === 0) {
            $('#wcm-results').html('<div class="notice notice-info"><p>No images found.</p></div>');
            return;
        }

        let html = '<div class="wcm-gallery">';
        data.hits.forEach(function (hit) {
            html += `
                <div class="wcm-image">
                    <img src="${hit.url}" alt="${hit.title}" />
                    <label>
                        <input type="checkbox" data-url="${hit.url}" data-id="${hit.id}" />
                        Select
                    </label>
                </div>
            `;
        });
        html += '</div>';

        // Pagination
        // Wikimedia Commons API does not provide total hits, so pagination is limited to fetched results
        html += '<div class="wcm-pagination">';
        if (currentPage > 1) {
            html += '<button id="wcm-prev-page" class="button">Previous</button>';
        }
        if (data.hits.length === 20) { // Assuming per_page is 20
            html += '<button id="wcm-next-page" class="button">Next</button>';
        }
        html += '</div>';

        $('#wcm-results').html(html);
    }

    // Handle pagination clicks
    $(document).on('click', '#wcm-next-page', function () {
        currentPage++;
        searchImages();
    });

    $(document).on('click', '#wcm-prev-page', function () {
        currentPage--;
        searchImages();
    });

    // Handle download button click
    $('#wcm-download-selected').on('click', function () {
        let selected = [];
        $('#wcm-results input[type="checkbox"]:checked').each(function () {
            selected.push({
                url: $(this).data('url'),
                id: $(this).data('id')
            });
        });

        if (selected.length === 0) {
            alert('No images selected.');
            return;
        }

        // Confirm action
        if (!confirm(`Are you sure you want to download ${selected.length} image(s)?`)) {
            return;
        }

        // Disable button to prevent multiple clicks
        $('#wcm-download-selected').prop('disabled', true).html('<span class="dashicons dashicons-download"></span> Downloading...');

        $.ajax({
            url: wcm_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'wcm_download_images',
                images: selected,
                query: currentQuery, // Include the current search query
                nonce: wcm_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data);
                    // Optionally, you can refresh the media library or perform other actions
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function () {
                alert('An unexpected error occurred.');
            },
            complete: function () {
                $('#wcm-download-selected').prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Download Selected');
            }
        });
    });
});

