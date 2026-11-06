document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('formCliente');
    const mensagemBox = document.getElementById('formMensagem');

    if (!form) return;

    // Máscara do telefone: (00) 00000-0000
    const campoTelefone = document.getElementById('telefone');

    function formatarTelefone(valor) {
        let d = valor.replace(/\D/g, '');

        // Colou com +55 / 55 na frente
        if (d.length > 11 && d.startsWith('55')) d = d.slice(2);

        d = d.slice(0, 11);

        if (d.length === 0) return '';
        if (d.length <= 2)  return `(${d}`;
        if (d.length <= 7)  return `(${d.slice(0, 2)}) ${d.slice(2)}`;
        return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
    }

    if (campoTelefone) {
        campoTelefone.addEventListener('input', function () {
            campoTelefone.value = formatarTelefone(campoTelefone.value);
        });
    }

    function mostrarMensagem(texto, tipo) {
        mensagemBox.innerHTML = `
            <div class="alert ${tipo === 'sucesso' ? 'sucesso' : 'erro'}">
                ${texto}
            </div>
        `;
    }

    form.addEventListener('submit', async function (evento) {
        evento.preventDefault();

        mensagemBox.innerHTML = '';

        const botao = form.querySelector('.submit-button');
        const textoOriginal = botao.textContent;
        botao.disabled = true;
        botao.textContent = 'Enviando...';

        try {
            const resposta = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form)
            });

            const dados = await resposta.json();

            if (dados.sucesso) {
                mostrarMensagem(dados.mensagem || 'Conta criada com sucesso!', 'sucesso');
                form.reset();
            } else {
                mostrarMensagem(dados.mensagem || 'Não foi possível concluir o cadastro.', 'erro');
            }

        } catch (erro) {
            mostrarMensagem('Erro de conexão. Tente novamente.', 'erro');
        } finally {
            botao.disabled = false;
            botao.textContent = textoOriginal;
        }
    });

});
