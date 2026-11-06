// ==============================
// TOAST
// ==============================

function mostrarToast(mensagem, tipo = 'success') {
    const toast = document.getElementById('toast');
    if (!toast) return;

    toast.textContent = mensagem;
    toast.className = 'toast show ' + tipo;

    clearTimeout(toast._timeout);
    toast._timeout = setTimeout(() => {
        toast.className = 'toast';
    }, 3500);
}

// ==============================
// CHAMADA AJAX PADRÃO
// ==============================

async function chamarApi(url, dados) {
    const corpo = new URLSearchParams(dados);

    const resposta = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: corpo,
    });

    let json;
    try {
        json = await resposta.json();
    } catch (e) {
        throw new Error('Resposta inválida do servidor.');
    }

    if (resposta.status === 401) {
        window.location.href = '../auth/login.php';
        throw new Error(json.mensagem || 'Sessão expirada.');
    }

    if (!json.sucesso) {
        throw new Error(json.mensagem || 'Não foi possível concluir a ação.');
    }

    return json;
}

// ==============================
// MODAIS
// ==============================

function abrirModal(id) {
    const overlay = document.getElementById(id);
    if (overlay) overlay.classList.add('open');
}

function fecharModal(id) {
    const overlay = document.getElementById(id);
    if (overlay) overlay.classList.remove('open');
}

document.addEventListener('click', (evento) => {
    if (evento.target.classList.contains('modal-overlay')) {
        evento.target.classList.remove('open');
    }
    if (evento.target.matches('[data-fechar-modal]')) {
        const overlay = evento.target.closest('.modal-overlay');
        if (overlay) overlay.classList.remove('open');
    }
});

// ==============================
// CONFIRMAÇÃO SIMPLES
// ==============================

function confirmarAcao(mensagem) {
    return window.confirm(mensagem);
}
