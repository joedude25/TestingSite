document.addEventListener("DOMContentLoaded", () => {
    const questionTextEl = document.getElementById("question-text");
    const optionsEl = document.getElementById("options");
    const actionEl = document.getElementById("action-buttons");
    const feedbackEl = document.getElementById("feedback");
    const explanationEl = document.getElementById("explanation");
    const counterEl = document.getElementById("question-counter");
    let totalQuestions = quizData.length;


    let currentIndex = 0;

    // --- Dynamic question generators ---
    const dynamicQuestionGenerators = 
    {
        eirp: function(template) 
        {
            const TX = Math.floor(Math.random() * 20) + 1;
            const loss = Math.floor(Math.random() * 15) + 1;
            const gain = Math.floor(Math.random() * 10) + 1;

            const questionText = template
                .replace('{{TX}}', TX)
                .replace('{{LOSS}}', loss)
                .replace('{{GAIN}}', gain);

            const answer = TX - loss + gain;
            return { questionText, answer };
        },
        fsplDistance: function(template) 
        {
              // Randomly pick a frequency
            const freqs = [
                { fq: "2.4 GHz", range: 140 },
                { fq: "5 GHz",   range: 80 },
                { fq: "6 GHz",   range: 50 }
            ];

            const choice = freqs[Math.floor(Math.random() * freqs.length)];

            // Replace {{FQ}} in template
            const questionText = template.replace("{{FQ}}", choice.fq);

            // The answer is the measured range in feet
            const answer = choice.range;

            return { questionText, answer };
        
        },
        ordering: function(template) 
        {
            // template items separated by semicolon
            const items = template.split(";").map(s => s.trim()).filter(s => s.length);
            // shuffle for initial display
            const shuffled = items
                .map(value => ({ value, sort: Math.random() }))
                .sort((a, b) => a.sort - b.sort)
                .map(obj => obj.value);
            return { items, shuffled };
        },
        draganddrop: function(template) 
        {
        // template: "term:definition; term2:definition2"

            const pairs = template
                .split(";")
                .map(s => s.trim())
                .filter(Boolean)
                .map(p => {
                    const [term, def] = p.split(":").map(x => x.trim());
                    return { term, definition: def };
                });

            const shuffledDefinitions = pairs
                .map(p => p.definition)
                .map(v => ({ v, r: Math.random() }))
                .sort((a, b) => a.r - b.r)
                .map(o => o.v);

            return { pairs, shuffledDefinitions };
        }


    };

    function showQuestion(index) 
    {
    if (index >= quizData.length) 
        {
        questionTextEl.textContent = "Quiz complete!";
        optionsEl.innerHTML = "";
        actionEl.innerHTML = "";
        feedbackEl.textContent = "";
        explanationEl.innerHTML = "";
        console.log("All questions completed.");
        return;
        }
    
    if (index < totalQuestions) {
    console.log("Updating counter:", index + 1, totalQuestions);
    counterEl.textContent = `Question ${index + 1} of ${totalQuestions}`;
    }

    const q = quizData[index];
    console.log("Displaying question:", q.question_id, "Type:", q.type, "Is new:", q.is_new);

    // Clear UI
    questionTextEl.textContent = "";
    optionsEl.innerHTML = "";
    actionEl.innerHTML = "";
    feedbackEl.textContent = "";
    explanationEl.innerHTML = "";

    let currentAnswer = null;
    let dynamicItems = null;

    if (q.type === "static") {
        renderMCQ(q);
    } else if (q.type === "ordering") {
        dynamicItems = renderOrdering(q);
    }  else if (q.type === "draganddrop") {
        dynamicItems = renderDragAndDrop(q);
    }  else if (q.type in dynamicQuestionGenerators) {
        currentAnswer = renderDynamic(q);
    }  else if (q.type === "imageclick") {
        renderImageClick(q);
    }
    


    setupSubmit(q, currentAnswer, dynamicItems);
    addDeleteButton(q);
    }

    function addDeleteButton(q) {
        const deleteBtn = document.createElement("button");
        deleteBtn.type = "button";
        deleteBtn.textContent = "Delete Question";
        deleteBtn.style.backgroundColor = "#a52828";
        deleteBtn.style.color = "white";
        deleteBtn.style.border = "0";
        deleteBtn.style.borderRadius = "4px";
        deleteBtn.style.padding = "7px 10px";
        deleteBtn.addEventListener("click", async () => {
            const questionIndex = quizData.indexOf(q);
            if (questionIndex < 0) return;
            if (!confirm(`Delete question #${q.question_id} and all its answers, explanation, and attempt history? This cannot be undone.`)) return;

            deleteBtn.disabled = true;
            try {
                const response = await fetch("delete_question.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ question_id: q.question_id })
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.error || "Question could not be deleted.");
                }

                quizData.splice(questionIndex, 1);
                totalQuestions = quizData.length;
                currentIndex = Math.min(questionIndex, quizData.length);
                showQuestion(currentIndex);
            } catch (error) {
                alert(error.message || "Question could not be deleted.");
                deleteBtn.disabled = false;
            }
        });
        actionEl.appendChild(deleteBtn);
    }

// --- Render functions ---

    function renderMCQ(q) 
    {
        questionTextEl.textContent = q.question_text;
        q.answers.forEach(ans => {
            const li = document.createElement("li");
            li.innerHTML = `<label><input type="radio" name="answer" value="${ans.id}"> ${ans.text}</label>`;
            optionsEl.appendChild(li);
        });
    }

    function renderDynamic(q) 
    {
        const result = dynamicQuestionGenerators[q.type](q.template);

        questionTextEl.textContent = q.question_text;

        const dynamicEl = document.createElement("div");
        dynamicEl.textContent = result.questionText;
        optionsEl.appendChild(dynamicEl);

        const inputEl = document.createElement("input");
        inputEl.type = "number";
        inputEl.placeholder = "Enter your answer";
        optionsEl.appendChild(inputEl);

        return result.answer;
    }

    function renderOrdering(q) 
    {
        if (!q.template) 
        {
        console.error(`Ordering question ${q.question_id} has no template!`);
        questionTextEl.textContent = q.question_text + " (No items available)";
        return [];
        }
        const result = dynamicQuestionGenerators.ordering(q.template);
        questionTextEl.textContent = q.question_text;

        result.shuffled.forEach(text => {
            const li = document.createElement("li");
            li.textContent = text;
            li.draggable = true;
            li.style.border = "1px solid #999";
            li.style.padding = "8px";
            li.style.margin = "5px 0";
            li.style.background = "#f9f9f9";
            li.style.cursor = "grab";
            optionsEl.appendChild(li);
        });

        let dragEl = null;

        optionsEl.addEventListener("dragstart", e => {
            dragEl = e.target;
            e.target.classList.add("dragging");
        });

        optionsEl.addEventListener("dragend", e => {
            e.target.classList.remove("dragging");
            dragEl = null;
        });

        optionsEl.addEventListener("dragover", e => {
            e.preventDefault();
            const afterEl = getDragAfterElement(optionsEl, e.clientY);
            const dragging = document.querySelector(".dragging");
            if (afterEl == null) {
                optionsEl.appendChild(dragging);
            } else {
                optionsEl.insertBefore(dragging, afterEl);
            }
        });

        function getDragAfterElement(container, y) 
        {
            const draggableEls = [...container.querySelectorAll("li:not(.dragging)")];
            return draggableEls.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        return result.items;
    }

    function renderImageClick(q) 
    {
        questionTextEl.textContent = q.question_text;

        const img = document.createElement("img");
        img.src = "images/" + q.template;
        img.style.width = "100%";
        img.style.maxWidth = "500px";
        img.style.position = "relative";
        img.style.display = "block";

        const wrapper = document.createElement("div");
        wrapper.style.position = "relative";
        wrapper.style.display = "inline-block";
        wrapper.appendChild(img);

        optionsEl.innerHTML = "";
        optionsEl.appendChild(wrapper);

        const regions = [
            { name: "E", correct: q.correct_answer === "E", x: 25, y: 50, size: 20 },
            { name: "H", correct: q.correct_answer === "H", x: 75, y: 50, size: 20 }
        ];

        regions.forEach(r => {
            const spot = document.createElement("div");
            spot.style.position = "absolute";
            spot.style.left = (r.x - r.size/2) + "%";
            spot.style.top = (r.y - r.size/2) + "%";
            spot.style.width = r.size + "%";
            spot.style.height = r.size + "%";
            spot.style.cursor = "pointer";
            spot.style.background = "rgba(255,0,0,0.0)";
            wrapper.appendChild(spot);

            spot.addEventListener("click", () => {
                const userAnswer = r.name;
                const isCorrect = r.correct;
                feedbackEl.textContent = isCorrect ? "Correct" : "Incorrect";
                spot.style.background = isCorrect
                    ? "rgba(0,255,0,0.3)"
                    : "rgba(255,0,0,0.3)";
            });
        });
    }

    function renderDragAndDrop(q) 
    {
        const { pairs, shuffledDefinitions } =
            dynamicQuestionGenerators.draganddrop(q.template);

        questionTextEl.textContent = q.question_text;

        const wrapper = document.createElement("div");
        wrapper.style.display = "flex";
        wrapper.style.gap = "40px";

        const left = document.createElement("div");
        const right = document.createElement("div");

        left.style.width = "40%";
        right.style.width = "40%";

        // TERMS (drop targets)
        pairs.forEach(p => {
            const box = document.createElement("div");
            box.textContent = p.term;
            box.dataset.correct = p.definition;
            box.dataset.user = "";
            box.style.border = "2px dashed #999";
            box.style.padding = "10px";
            box.style.marginBottom = "10px";

            box.addEventListener("dragover", e => e.preventDefault());

            box.addEventListener("drop", e => {
                e.preventDefault();
                const text = e.dataTransfer.getData("text/plain");
                box.dataset.user = text;
                box.textContent = `${p.term} ← ${text}`;
            });

            left.appendChild(box);
        });

        // DEFINITIONS (draggables)
        shuffledDefinitions.forEach(def => {
            const item = document.createElement("div");
            item.textContent = def;
            item.draggable = true;
            item.style.border = "1px solid #666";
            item.style.padding = "8px";
            item.style.marginBottom = "10px";
            item.style.cursor = "grab";

            item.addEventListener("dragstart", e => {
                e.dataTransfer.setData("text/plain", def);
            });

            right.appendChild(item);
        });

        wrapper.appendChild(left);
        wrapper.appendChild(right);
        optionsEl.appendChild(wrapper);

        return pairs;
    }


// --- Submit / record / difficulty handling ---

    function setupSubmit(q, currentAnswer, dynamicItems) 
    {
        const submitBtn = document.createElement("button");
        submitBtn.textContent = "Submit";
        actionEl.appendChild(submitBtn);

        submitBtn.addEventListener("click", async () => {
            let isCorrect = false;
            let userAnswer = null;

            if (
                q.type in dynamicQuestionGenerators &&
                q.type !== "ordering" &&
                q.type !== "draganddrop"
            ) {
                const inputVal = parseFloat(optionsEl.querySelector("input").value);
                userAnswer = inputVal;
                isCorrect = Math.abs(inputVal - currentAnswer) < 0.001;
            } else if (q.type === "ordering") {
                const userOrder = [...optionsEl.children].map(li => li.textContent);
                userAnswer = userOrder;
                isCorrect = userOrder.every((val, idx) => val === dynamicItems[idx]);
                console.log("Ordering question:", q.question_id, "User order:", userOrder, "Expected:", dynamicItems, "Correct:", isCorrect);
            } else if (q.type === "draganddrop") {
                const targets = optionsEl.querySelectorAll("[data-correct]");
                userAnswer = [];
                isCorrect = true;

                targets.forEach(t => {
                    const expected = t.dataset.correct;
                    const actual = t.dataset.user;
                    userAnswer.push({ term: t.textContent, answer: actual });

                    if (expected !== actual) {
                        isCorrect = false;
                        t.style.background = "#ffe6e6";
                    } else {
                        t.style.background = "#e6ffe6";
                    }
                });

                console.log("Drag & Drop:", userAnswer, "Correct:", isCorrect);
            }

            else if (q.type === "static") {
                const selectedInput = optionsEl.querySelector('input[name="answer"]:checked');
                if (!selectedInput) return alert("Select an answer first.");
                const selectedId = parseInt(selectedInput.value);
                const selectedAnswer = q.answers.find(a => a.id === selectedId);
                userAnswer = selectedId;
                isCorrect = !!selectedAnswer.correct;
                console.log("MCQ question:", q.question_id, "Selected:", selectedId, "Correct:", isCorrect);

                highlightMCQ(q, userAnswer);
            }

            feedbackEl.textContent = isCorrect ? "✅ Correct!" : "❌ Incorrect";
            explanationEl.innerHTML = q.explanation?.trim() || "No explanation available.";

            try {
                const response = await fetch("record_answer.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        user_id: userId,
                        question_id: q.question_id,
                        answer_id: userAnswer,
                        answer_ids: q.answers?.map(a => a.id) || [],
                        answers: q.answers,
                        correct: isCorrect
                    })
                });
                const data = await response.json();
                if (data.success) {
                    console.log(`Answer recorded successfully for question ${q.question_id}`);
                } else {
                    console.error(`Failed to record answer for question ${q.question_id}`, data.error || data);
                }
            } catch (err) {
                console.error("Error recording answer:", err);
            }

            // Back / Next / difficulty buttons
            actionEl.innerHTML = "";

            const backBtn = document.createElement("button");
            backBtn.textContent = "Back";
            actionEl.appendChild(backBtn);
            backBtn.addEventListener("click", () => {
                if (currentIndex > 0) {
                    currentIndex--;
                    showQuestion(currentIndex);
                }
            });

            if (q.is_new) {
                const nextBtn = document.createElement("button");
                nextBtn.textContent = "Next";
                actionEl.appendChild(nextBtn);
                nextBtn.addEventListener("click", () => {
                    currentIndex++;
                    showQuestion(currentIndex);
                });
            } else {
                ["easy","good","hard"].forEach(diff => {
                    const btn = document.createElement("button");
                    btn.textContent = diff.charAt(0).toUpperCase() + diff.slice(1);
                    btn.dataset.diff = diff;
                    actionEl.appendChild(btn);

                    btn.addEventListener("click", async () => {
                        let newDifficulty = q.difficulty;
                        if (diff === "easy") newDifficulty = Math.max(1, q.difficulty - 1);
                        if (diff === "good") newDifficulty = q.difficulty;
                        if (diff === "hard") newDifficulty = Math.min(5, q.difficulty + 1);

                        console.log(`Adjusting difficulty for question ${q.question_id}: ${q.difficulty} → ${newDifficulty}`);

                        try {
                            const resp = await fetch("update_question_difficulty.php", {
                                method: "POST",
                                headers: { "Content-Type": "application/json" },
                                body: JSON.stringify({
                                    question_id: q.question_id,
                                    difficulty: newDifficulty
                                })
                            });
                            const data = await resp.json();
                            console.log("Update difficulty response:", data);
                            q.difficulty = newDifficulty;
                        } catch (err) {
                            console.error("Error updating difficulty:", err);
                        }

                        currentIndex++;
                        showQuestion(currentIndex);
                    });
                });
            }

            addDeleteButton(q);
        });
    }

    function highlightMCQ(q, userAnswer) 
    {
        optionsEl.querySelectorAll('input[name="answer"]').forEach(input => {
            const ansId = parseInt(input.value);
            const li = input.closest("li");
            if (q.answers.find(a => a.id === ansId).correct) li.classList.add("correct");
            if (ansId === userAnswer) li.classList.add("selected");
            input.disabled = true;
        });
    }


  

                    
 
    showQuestion(currentIndex);
});
