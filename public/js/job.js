document.addEventListener("DOMContentLoaded", function () {
    const jobOutput = document.getElementById('jobOutput');
    const jobStatus = document.getElementById('jobStatus');

    if (!jobOutput || !jobStatus) return;

    const STATUS_RUNNING = jobStatus.dataset.status_running.trim();
    const STATUS_PLANNED = jobStatus.dataset.status_planned.trim();

    let autoScrollEnabled = true;
    let isFetching = false;
    let currentLength = jobOutput.innerHTML.length;
    let currentSequence = 0;
    let latestSequence = 0;
    let interval;

    // Scroll to input bottom on page load
    scrollToBottom(jobOutput);

    // Set auto scroll of output if user is near the bottom
    jobOutput.addEventListener('scroll', () => {
        const nearBottom = jobOutput.scrollHeight - jobOutput.scrollTop - jobOutput.clientHeight;
        autoScrollEnabled = nearBottom <= 10;
    });

    // Set interval for ajax update if job is planned or running
    if ([STATUS_RUNNING, STATUS_PLANNED].includes(jobStatus.innerHTML.trim())) {
        interval = setInterval(() => {
            ajaxUpdateOutput();
            if (autoScrollEnabled) {
                scrollToBottom(jobOutput);
            }
        }, jobStatus.innerHTML.trim() === STATUS_PLANNED ? 5000 : 500); // 5s for planned 0.5s for running
    }

    // Scroll to the bottom of the output
    function scrollToBottom(elem) {
        elem.scrollTo({top: elem.scrollHeight, behavior: 'instant'});
    }

    // Update the job output when job is running
    function ajaxUpdateOutput() {
        // Prevent starting a new request if one is already being fetched
        if (isFetching) return;
        isFetching = true;

        currentSequence++;
        const thisRequestSequence = currentSequence;
        const ajaxPath = jobOutput.dataset.ajax_path;
        const separator = ajaxPath.includes('?') ? '&' : '?';

        fetch(`${ajaxPath}${separator}length=${currentLength}`, {method: 'GET'})
            .then(response => response.json())
            .then(data => {
                // Only process the response if it's the latest one.
                if (thisRequestSequence < latestSequence) return;
                latestSequence = thisRequestSequence;

                if (data.output && data.output.length > 0) {
                    if (jobStatus.innerHTML.trim() === STATUS_PLANNED) {
                        // If the job is planned refresh the page
                        setTimeout(() => window.location.reload(), 500);
                        return;
                    } else {
                        if (jobOutput.parentElement.style.display === 'none') {
                            // Update output if the job is running
                            jobOutput.parentElement.style.display = 'block';
                        }
                        // Append output to the current output and update current length
                        jobOutput.innerHTML += data.output;
                        currentLength = data.length;
                    }
                }

                // When the job is finished, clear the interval and refresh
                if (data.finished) {
                    clearInterval(interval);
                    setTimeout(() => window.location.reload(), 500);
                }
            })
            .catch(error => {
                console.error('Error fetching output:', error);
            })
            .finally(() => {
                // Release the lock so the next request can proceed
                isFetching = false;
            });
    }
});
